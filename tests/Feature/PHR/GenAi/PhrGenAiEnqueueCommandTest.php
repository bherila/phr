<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class PhrGenAiEnqueueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Storage::fake('phr_documents');
        Bus::fake();
    }

    public function test_external_actor_cli_import_links_a_source_document_and_reaches_external_enqueue(): void
    {
        [$actor, $patient, $owner] = $this->manageablePatient(GenAiImportJob::EXECUTION_EXTERNAL);
        $path = $this->writeSourceFile();

        $this->artisan('phr:genai:enqueue', [
            '--patient' => $patient->id,
            '--actor' => $actor->id,
            '--file' => $path,
        ])->assertExitCode(0);

        $document = PhrDocument::query()->where('patient_id', $patient->id)->sole();
        $job = GenAiImportJob::query()->sole();

        // The durable relationship the external queue authorizes against.
        $this->assertSame((int) $job->id, (int) $document->genai_job_id);
        $this->assertSame((int) $document->id, (int) $job->sourceDocument()->sole()->id);
        $this->assertSame((int) $patient->id, (int) $document->patient_id);
        $this->assertSame((int) $owner->id, (int) $document->user_id);
        $this->assertSame((int) $actor->id, (int) $document->uploaded_by_user_id);
        $this->assertSame(pathinfo(basename($path), PATHINFO_FILENAME), $document->title);
        $this->assertSame('other', $document->document_type);
        $this->assertSame(basename($path), $document->original_filename);
        $this->assertSame(PhrDocument::STORAGE_DISK, $document->storage_disk);
        $this->assertSame('manual_upload', $document->source);
        $this->assertSame(hash('sha256', $this->documentBytes()), $document->file_hash);
        $this->assertSame(strlen($this->documentBytes()), (int) $document->byte_size);
        $this->assertNotNull($document->mime_type);
        $this->assertNotNull($document->imported_at);
        Storage::disk(PhrDocument::STORAGE_DISK)->assertExists((string) $document->storage_path);

        $this->assertSame('phr_document', $job->job_type);
        $this->assertSame(GenAiImportJob::EXECUTION_EXTERNAL, $job->execution_mode);
        $this->assertSame('pending', $job->status);
        $this->assertSame((int) $actor->id, (int) $job->user_id);
        $this->assertSame((int) $patient->id, (int) ($job->getContextArray()['patient_id'] ?? 0));
        $this->assertSame((int) $document->id, (int) ($job->getContextArray()['document_id'] ?? 0));
        Storage::disk('s3')->assertExists($job->s3_path);
        Bus::assertDispatched(ParseImportJob::class);

        // Before the fix this threw 'The source document is no longer available.'
        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertNotNull($job->mcp_request_id);
        $this->assertSame('mcp', $job->ai_provider);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->error_message);
        $this->assertNull($job->ai_configuration_id);
    }

    public function test_external_actor_cli_import_of_a_typed_extraction_also_carries_a_source_document(): void
    {
        [$actor, $patient] = $this->manageablePatient(GenAiImportJob::EXECUTION_EXTERNAL);

        $this->artisan('phr:genai:enqueue', [
            '--patient' => $patient->id,
            '--actor' => $actor->id,
            '--file' => $this->writeSourceFile(),
            '--type' => 'phr_lab_result',
            '--document-type' => 'lab_report',
        ])->assertExitCode(0);

        $job = GenAiImportJob::query()->sole();
        $this->assertSame('phr_lab_result', $job->job_type);
        $this->assertSame('lab_report', PhrDocument::query()->sole()->document_type);

        (new ParseImportJob($job->id))->handle();

        $this->assertNotNull($job->refresh()->mcp_request_id);
    }

    public function test_api_actor_cli_import_keeps_the_existing_api_execution_path(): void
    {
        [$actor, $patient] = $this->manageablePatient(GenAiImportJob::EXECUTION_API);

        $this->artisan('phr:genai:enqueue', [
            '--patient' => $patient->id,
            '--actor' => $actor->id,
            '--file' => $this->writeSourceFile(),
        ])->assertExitCode(0);

        $job = GenAiImportJob::query()->sole();
        $this->assertSame(GenAiImportJob::EXECUTION_API, $job->execution_mode);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->mcp_request_id);
        Bus::assertDispatched(ParseImportJob::class, fn (ParseImportJob $dispatched): bool => $dispatched->jobId === (int) $job->id);

        // An API-mode actor without a configuration still terminates on the API
        // branch exactly as before, never on the subscription-client branch.
        (new ParseImportJob($job->id))->handle();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('No AI configuration found. Please add one in Settings.', $job->error_message);
        $this->assertNull($job->mcp_request_id);
        $this->assertDatabaseCount('genai_mcp_requests', 0);
    }

    public function test_unsupported_job_type_fails_terminally_without_staging_anything(): void
    {
        [$actor, $patient] = $this->manageablePatient(GenAiImportJob::EXECUTION_EXTERNAL);

        $this->artisan('phr:genai:enqueue', [
            '--patient' => $patient->id,
            '--actor' => $actor->id,
            '--file' => $this->writeSourceFile(),
            '--type' => 'tax_document',
        ])->assertExitCode(1);

        $this->assertNothingStaged();
    }

    public function test_unsupported_document_type_fails_terminally_without_staging_anything(): void
    {
        [$actor, $patient] = $this->manageablePatient(GenAiImportJob::EXECUTION_EXTERNAL);

        $this->artisan('phr:genai:enqueue', [
            '--patient' => $patient->id,
            '--actor' => $actor->id,
            '--file' => $this->writeSourceFile(),
            '--document-type' => 'not_a_document_type',
        ])->assertExitCode(1);

        $this->assertNothingStaged();
    }

    public function test_unreadable_file_fails_terminally_without_staging_anything(): void
    {
        [$actor, $patient] = $this->manageablePatient(GenAiImportJob::EXECUTION_EXTERNAL);

        try {
            $this->artisan('phr:genai:enqueue', [
                '--patient' => $patient->id,
                '--actor' => $actor->id,
                '--file' => sys_get_temp_dir().'/phr-missing-'.uniqid().'.pdf',
            ])->run();
            $this->fail('An unreadable --file should not be accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('--file must be a readable file path.', $exception->getMessage());
        }

        $this->assertNothingStaged();
    }

    public function test_actor_without_write_access_cannot_stage_a_document(): void
    {
        [, $patient] = $this->manageablePatient(GenAiImportJob::EXECUTION_EXTERNAL);
        $stranger = User::factory()->create(['user_role' => 'user']);

        try {
            $this->artisan('phr:genai:enqueue', [
                '--patient' => $patient->id,
                '--actor' => $stranger->id,
                '--file' => $this->writeSourceFile(),
            ])->run();
            $this->fail('A user without write access should not stage a document.');
        } catch (AuthorizationException|ModelNotFoundException) {
            // Either boundary is a terminal refusal.
        }

        $this->assertNothingStaged();
    }

    private function assertNothingStaged(): void
    {
        $this->assertDatabaseCount('genai_import_jobs', 0);
        $this->assertSame(0, PhrDocument::withTrashed()->count());
        Bus::assertNotDispatched(ParseImportJob::class);
    }

    /** @return array{0: User, 1: PhrPatient, 2: User} */
    private function manageablePatient(string $executionMode): array
    {
        $actor = User::factory()->create([
            'user_role' => 'user',
            'genai_execution_mode' => $executionMode,
        ]);
        $owner = User::factory()->create(['user_role' => 'user']);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'CLI Enqueue Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'access_level' => PhrPatientUserAccess::LEVEL_MANAGER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        return [$actor, $patient, $owner];
    }

    private function writeSourceFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phr-cli-');
        $this->assertIsString($path);
        $pdfPath = $path.'-source-report.pdf';
        rename($path, $pdfPath);
        file_put_contents($pdfPath, $this->documentBytes());

        return $pdfPath;
    }

    private function documentBytes(): string
    {
        return "%PDF-1.4\nsynthetic cli source document\n%%EOF\n";
    }
}
