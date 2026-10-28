<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AI imports on the browser's Imports page (#235, PR 5): a patient's jobs and
 * their proposals, reviewed through the same service as the agent API.
 */
class PhrAiImportRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(PhrDocument::STORAGE_DISK);
        Storage::fake('s3');
        Queue::fake();
    }

    public function test_a_patients_ai_imports_are_listed_with_where_they_run(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $viewer = $this->grantee($patient, PhrPatientUserAccess::LEVEL_VIEWER);
        $inbox = $this->job($this->document($patient, $owner, 'letter.pdf'), $owner, 'pending', GenAiImportJob::EXECUTION_EXTERNAL);
        $parsed = $this->job($this->document($patient, $owner, 'labs.pdf'), $owner, 'parsed', GenAiImportJob::EXECUTION_API);
        $this->proposal($parsed, 0, ['analyte' => 'Synthetic sodium']);
        [$otherOwner, $otherPatient] = $this->ownerAndPatient();
        $this->job($this->document($otherPatient, $otherOwner, 'other.pdf'), $otherOwner, 'pending', GenAiImportJob::EXECUTION_EXTERNAL);

        $listed = $this->actingAs($viewer)->getJson($this->base($patient))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->json('imports');

        self::assertSame([$parsed->id, $inbox->id], array_column($listed, 'id'), 'this patient only, newest first');
        self::assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $listed[1]['execution_mode']);
        self::assertSame('Synthetic letter.pdf', $listed[1]['document_title']);
        self::assertSame(1, $listed[0]['pending_result_count']);
        $this->actingAs($this->createUser())->getJson($this->base($patient))->assertNotFound();
    }

    public function test_proposals_are_shown_then_accepted_or_rejected_by_someone_who_manages_the_patient(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $viewer = $this->grantee($patient, PhrPatientUserAccess::LEVEL_VIEWER);
        $job = $this->job($this->document($patient, $owner, 'labs.pdf'), $owner, 'parsed', GenAiImportJob::EXECUTION_EXTERNAL);
        $sodium = $this->proposal($job, 0, ['analyte' => 'Synthetic sodium', 'value' => '140']);
        $potassium = $this->proposal($job, 1, ['analyte' => 'Synthetic potassium', 'value' => '4.1']);
        $url = $this->base($patient)."/{$job->id}";

        $this->actingAs($viewer)->getJson($url)->assertOk()
            ->assertJsonPath('import.results.0.data.analyte', 'Synthetic sodium')
            ->assertJsonPath('import.results.1.status', 'pending_review');
        $this->actingAs($viewer)->postJson("{$url}/results/{$sodium->id}/accept")->assertForbidden();

        $this->actingAs($owner)->postJson("{$url}/results/{$sodium->id}/accept")->assertOk()
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('import.created', 1);
        self::assertSame('Synthetic sodium', PhrLabResult::query()->where('patient_id', $patient->id)->sole()->analyte);
        $this->actingAs($owner)->postJson("{$url}/results/{$potassium->id}/reject")->assertOk()
            ->assertJsonPath('outcome', 'rejected')
            ->assertJsonPath('result.status', 'skipped');
        self::assertSame(1, PhrLabResult::query()->count());

        // A result of another patient's job is not reachable through this patient.
        [$otherOwner, $otherPatient] = $this->ownerAndPatient();
        $foreign = $this->proposal($this->job($this->document($otherPatient, $otherOwner, 'x.pdf'), $otherOwner, 'parsed', GenAiImportJob::EXECUTION_API), 0, ['analyte' => 'Synthetic foreign']);
        $this->actingAs($owner)->postJson("{$url}/results/{$foreign->id}/accept")->assertNotFound();
    }

    public function test_a_failed_import_can_be_tried_again(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $job = $this->job($this->document($patient, $owner, 'scan.pdf'), $owner, 'failed', GenAiImportJob::EXECUTION_EXTERNAL);

        $this->actingAs($owner)->postJson($this->base($patient)."/{$job->id}/retry")->assertStatus(202)
            ->assertJsonPath('import.status', 'pending');
        Queue::assertPushed(ParseImportJob::class, 1);
    }

    private function base(PhrPatient $patient): string
    {
        return "/api/phr/patients/{$patient->id}/ai-imports";
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    private function grantee(PhrPatient $patient, string $level): User
    {
        $user = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $user->id, 'access_level' => $level, 'granted_by_user_id' => $patient->owner_user_id, 'granted_at' => now()]);

        return $user;
    }

    private function document(PhrPatient $patient, User $owner, string $filename): PhrDocument
    {
        $path = "patients/{$patient->id}/documents/synthetic/{$filename}";
        $contents = '%PDF-1.4 synthetic '.$filename;
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $contents);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic '.$filename, 'document_type' => 'lab_report', 'original_filename' => $filename,
            'storage_disk' => PhrDocument::STORAGE_DISK, 'storage_path' => $path, 'mime_type' => 'application/pdf',
            'byte_size' => strlen($contents), 'file_hash' => hash('sha256', $contents), 'source' => 'manual_upload',
        ]);
    }

    private function job(PhrDocument $document, User $actor, string $status, string $mode): GenAiImportJob
    {
        $staging = 'genai-import/'.$actor->id.'/synthetic-'.$document->id.'/'.$document->original_filename;
        Storage::disk('s3')->put($staging, 'synthetic staging');
        $job = GenAiImportJob::query()->create([
            'user_id' => $actor->id, 'job_type' => 'phr_lab_result', 'file_hash' => $document->file_hash,
            'original_filename' => $document->original_filename, 's3_path' => $staging, 'mime_type' => $document->mime_type,
            'file_size_bytes' => $document->byte_size, 'execution_mode' => $mode, 'status' => $status,
            'context_json' => json_encode(['patient_id' => $document->patient_id, 'document_id' => $document->id], JSON_THROW_ON_ERROR),
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return $job;
    }

    /** @param array<string, mixed> $payload */
    private function proposal(GenAiImportJob $job, int $index, array $payload): GenAiImportResult
    {
        return GenAiImportResult::query()->create([
            'job_id' => $job->id, 'result_index' => $index, 'status' => 'pending_review', 'produced_by' => 'synthetic',
            'result_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
