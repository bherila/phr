<?php

namespace Tests\Feature\PHR;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhrDocumentsTest extends TestCase
{
    public function test_owner_can_upload_and_filter_documents(): void
    {
        Storage::fake('phr_documents');

        $owner = $this->createUser();
        $patient = $this->createPatient($owner);

        $response = $this->actingAs($owner)->post("/api/phr/patients/{$patient->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('lab.pdf', '%PDF-1.4 lab'),
            'title' => 'January Labs',
            'document_type' => 'lab_report',
            'observed_at' => '2026-01-15 10:30:00',
            'summary' => 'CBC and metabolic panel',
            'tags' => ['labs', 'mychart'],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('document.title', 'January Labs')
            ->assertJsonPath('document.document_type', 'lab_report')
            ->assertJsonPath('document.source', 'manual_upload')
            ->assertJsonPath('document.tags.0', 'labs');

        $document = PhrDocument::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame('January Labs', $document->title);
        $this->assertSame('manual_upload', $document->source);
        $this->assertSame('labs', $document->tags[0]);
        Storage::disk('phr_documents')->assertExists((string) $document->storage_path);

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patient->id}/documents?type=lab_report&source=manual_upload&tag=labs&date_from=2026-01-01&date_to=2026-01-31")
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('documents.0.id', $document->id);
    }

    public function test_file_proxy_is_inline_for_accessible_patient_and_404_for_others(): void
    {
        Storage::fake('phr_documents');

        $owner = $this->createUser();
        $other = $this->createUser();
        $patient = $this->createPatient($owner);
        $document = $this->createStoredDocument($patient, $owner, 'source/lab.pdf', '%PDF-1.4 lab');

        $response = $this->actingAs($owner)
            ->get("/api/phr/patients/{$patient->id}/documents/{$document->id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="lab.pdf"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertIsString($csp);
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);

        $this->actingAs($other)
            ->get("/api/phr/patients/{$patient->id}/documents/{$document->id}/file")
            ->assertNotFound();
    }

    public function test_soft_delete_hides_document_and_keeps_file_on_disk(): void
    {
        Storage::fake('phr_documents');

        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $document = $this->createStoredDocument($patient, $owner, 'source/lab.pdf', '%PDF-1.4 lab');

        $this->actingAs($owner)
            ->deleteJson("/api/phr/patients/{$patient->id}/documents/{$document->id}")
            ->assertNoContent();

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patient->id}/documents")
            ->assertOk()
            ->assertJsonCount(0, 'documents');

        $this->assertSoftDeleted('phr_documents', ['id' => $document->id]);
        Storage::disk('phr_documents')->assertExists('source/lab.pdf');
    }

    public function test_process_with_genai_stages_existing_file_and_links_job(): void
    {
        Storage::fake('phr_documents');
        Storage::fake('s3');
        Queue::fake();

        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $document = $this->createStoredDocument($patient, $owner, 'source/lab.pdf', '%PDF-1.4 lab');

        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patient->id}/documents/{$document->id}/process")
            ->assertAccepted()
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('status', 'pending');

        $job = GenAiImportJob::query()->where('job_type', 'phr_document')->sole();
        $document->refresh();
        $this->assertSame($job->id, (int) $document->genai_job_id);
        $this->assertSame($document->id, (int) ($job->getContextArray()['document_id'] ?? 0));
        Storage::disk('s3')->assertExists($job->s3_path);
        Queue::assertPushed(ParseImportJob::class, fn (ParseImportJob $queuedJob): bool => $queuedJob->jobId === $job->id);
    }

    public function test_metadata_response_includes_linked_rows_created_from_document(): void
    {
        Storage::fake('phr_documents');

        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $document = $this->createStoredDocument($patient, $owner, 'source/lab.pdf', '%PDF-1.4 lab');

        PhrLabResult::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'source_document_id' => $document->id,
            'test_name' => 'CBC',
            'analyte' => 'Hemoglobin',
            'value' => '14.1',
        ]);

        $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patient->id}/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('document.linked_rows.0.type', 'lab_result')
            ->assertJsonPath('document.linked_rows.0.label', 'Hemoglobin');
    }

    private function createPatient(User $owner): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Test Patient',
            'relationship' => 'self',
        ]);
    }

    private function createStoredDocument(PhrPatient $patient, User $owner, string $path, string $contents): PhrDocument
    {
        Storage::disk('phr_documents')->put($path, $contents);
        $hash = hash('sha256', $contents);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Lab PDF',
            'document_type' => 'lab_report',
            'observed_at' => '2026-01-15 10:30:00',
            'original_filename' => 'lab.pdf',
            'storage_disk' => 'phr_documents',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($contents),
            'file_hash' => $hash,
            'source' => 'manual_upload',
            'tags' => ['labs'],
        ]);
    }
}
