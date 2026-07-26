<?php

namespace Tests\Feature\PHR\GenAi;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\GenAiProcessor\Services\Prompts\Phr\PhrPromptTemplate;
use App\Models\PhrCondition;
use App\Models\PhrDocument;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrNegativeAssertion;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\PhrPortalMessage;
use App\Models\User;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use Tests\TestCase;

class PhrGenAiImportTest extends TestCase
{
    public function test_accepting_phr_genai_lab_result_creates_patient_row(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $job = $this->createJob($owner, $patient, 'phr_lab_result');
        $result = GenAiImportResult::create([
            'job_id' => $job->id,
            'result_index' => 0,
            'status' => 'pending_review',
            'result_json' => json_encode([
                'external_id' => 'genai-lab-1',
                'test_name' => 'CMP',
                'analyte' => 'Creatinine',
                'value' => '0.9',
                'unit' => 'mg/dL',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($owner)
            ->postJson("/api/phr/genai/jobs/{$job->id}/results/{$result->id}/accept")
            ->assertOk()
            ->assertJsonPath('import.created', 1);

        $this->assertSame(1, PhrLabResult::query()->where('patient_id', $patient->id)->count());
        $this->assertSame('Creatinine', PhrLabResult::query()->where('patient_id', $patient->id)->sole()->analyte);
        $this->assertSame('imported', $result->refresh()->status);
        $this->assertSame('imported', $job->refresh()->status);
    }

    public function test_viewer_cannot_accept_phr_genai_result(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patient = $this->createPatient($owner);
        PhrPatientUserAccess::create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $job = $this->createJob($viewer, $patient, 'phr_lab_result');
        $result = GenAiImportResult::create([
            'job_id' => $job->id,
            'result_index' => 0,
            'status' => 'pending_review',
            'result_json' => json_encode(['analyte' => 'Creatinine'], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($viewer)
            ->postJson("/api/phr/genai/jobs/{$job->id}/results/{$result->id}/accept")
            ->assertForbidden();

        $this->assertSame(0, PhrLabResult::query()->where('patient_id', $patient->id)->count());
        $this->assertSame('pending_review', $result->refresh()->status);
    }

    public function test_accepting_phr_document_bundle_imports_nested_records_without_duplicates(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $document = $this->createDocument($owner, $patient);
        $payload = $this->documentBundlePayload();

        $firstJob = $this->createJob($owner, $patient, 'phr_document', ['document_id' => $document->id]);
        $firstResult = $this->createResult($firstJob, $payload);

        $this->actingAs($owner)
            ->postJson("/api/phr/genai/jobs/{$firstJob->id}/results/{$firstResult->id}/accept")
            ->assertOk()
            ->assertJsonPath('import.documents', 1)
            ->assertJsonPath('import.created', 4)
            ->assertJsonPath('import.updated', 1);

        $this->assertSame('ENT visit bundle', $document->refresh()->title);
        $this->assertSame('office_visit_note', $document->document_type);
        $this->assertSame('Bundle summary', $document->summary);
        $this->assertSame('doc-condition-nasal-obstruction', PhrCondition::query()->where('patient_id', $patient->id)->sole()->external_id);
        $this->assertSame($document->id, PhrMedication::query()->where('patient_id', $patient->id)->sole()->source_document_id);
        $this->assertSame('Care team message', PhrPortalMessage::query()->where('patient_id', $patient->id)->sole()->subject);
        $this->assertSame('allergy_absence', PhrNegativeAssertion::query()->where('patient_id', $patient->id)->sole()->assertion_type);

        $secondJob = $this->createJob($owner, $patient, 'phr_document', ['document_id' => $document->id]);
        $secondResult = $this->createResult($secondJob, $payload);

        $this->actingAs($owner)
            ->postJson("/api/phr/genai/jobs/{$secondJob->id}/results/{$secondResult->id}/accept")
            ->assertOk()
            ->assertJsonPath('import.documents', 1)
            ->assertJsonPath('import.created', 0)
            ->assertJsonPath('import.updated', 5);

        $this->assertSame(1, PhrCondition::query()->where('patient_id', $patient->id)->count());
        $this->assertSame(1, PhrMedication::query()->where('patient_id', $patient->id)->count());
        $this->assertSame(1, PhrPortalMessage::query()->where('patient_id', $patient->id)->count());
        $this->assertSame(1, PhrNegativeAssertion::query()->where('patient_id', $patient->id)->count());
    }

    public function test_accepting_genai_record_ignores_payload_supplied_source_document_id(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $otherPatient = $this->createPatient($owner);
        $foreignDocument = $this->createDocument($owner, $otherPatient);

        $job = $this->createJob($owner, $patient, 'phr_lab_result');
        $result = GenAiImportResult::create([
            'job_id' => $job->id,
            'result_index' => 0,
            'status' => 'pending_review',
            'result_json' => json_encode([
                'external_id' => 'genai-lab-untrusted',
                'analyte' => 'Sodium',
                'source_document_id' => $foreignDocument->id,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($owner)
            ->postJson("/api/phr/genai/jobs/{$job->id}/results/{$result->id}/accept")
            ->assertOk();

        $this->assertNull(PhrLabResult::query()->where('patient_id', $patient->id)->sole()->source_document_id);
    }

    public function test_document_bundle_does_not_reuse_document_external_id_for_child_records(): void
    {
        $owner = $this->createUser();
        $patient = $this->createPatient($owner);
        $document = $this->createDocument($owner, $patient);

        app(PhrStructuredDataImporter::class)->importDocumentBundle(
            $patient,
            (int) $owner->id,
            $document,
            $this->documentBundlePayload(),
            ['import_source' => 'genai', 'external_id' => 'source-ent-visit'],
        );

        $this->assertSame('doc-condition-nasal-obstruction', PhrCondition::query()->where('patient_id', $patient->id)->sole()->external_id);
        $this->assertSame('doc-med-fluticasone', PhrMedication::query()->where('patient_id', $patient->id)->sole()->external_id);
    }

    public function test_phr_document_prompt_requests_import_bundle_schema(): void
    {
        $prompt = (new PhrPromptTemplate('phr_document'))->build([]);

        $this->assertStringContainsString('"schema_version": "phr_pdf_bundle.v1"', $prompt);
        $this->assertStringContainsString('"portal_messages"', $prompt);
        $this->assertStringContainsString('"negative_assertions"', $prompt);
        $this->assertStringContainsString('"source_refs"', $prompt);
        $this->assertStringNotContainsString('visit_summary', $prompt);
    }

    private function createPatient(User $owner): PhrPatient
    {
        return PhrPatient::create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Test Patient',
            'relationship' => 'self',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createJob(User $user, PhrPatient $patient, string $jobType, array $context = []): GenAiImportJob
    {
        return GenAiImportJob::create([
            'user_id' => $user->id,
            'job_type' => $jobType,
            'file_hash' => hash('sha256', $jobType.$patient->id.$user->id.json_encode($context, JSON_THROW_ON_ERROR).microtime()),
            'original_filename' => 'source.pdf',
            's3_path' => 'genai-import/source.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 100,
            'context_json' => json_encode(['patient_id' => $patient->id, ...$context], JSON_THROW_ON_ERROR),
            'status' => 'parsed',
        ]);
    }

    private function createDocument(User $owner, PhrPatient $patient): PhrDocument
    {
        return PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Source document',
            'document_type' => 'other',
            'original_filename' => 'source.pdf',
            'storage_disk' => 'phr_documents',
            'storage_path' => 'phr/documents/source.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 100,
            'file_hash' => hash('sha256', 'source-document'),
            'source' => 'manual_upload',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createResult(GenAiImportJob $job, array $payload): GenAiImportResult
    {
        return GenAiImportResult::create([
            'job_id' => $job->id,
            'result_index' => 0,
            'status' => 'pending_review',
            'result_json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentBundlePayload(): array
    {
        return [
            'schema_version' => 'phr_pdf_bundle.v1',
            'source_document' => [
                'record_key' => 'source-ent-visit',
                'title' => 'ENT visit bundle',
                'document_type' => 'office_visit_note',
                'summary' => 'Bundle summary',
                'extracted_text' => 'Important source text.',
                'tags' => ['ENT'],
            ],
            'records' => [
                'conditions' => [[
                    'record_key' => 'doc-condition-nasal-obstruction',
                    'name' => 'Nasal obstruction',
                    'clinical_status' => 'active',
                    'verification_status' => 'confirmed',
                    'source_refs' => ['page 1'],
                    'raw_text' => 'Assessment: nasal obstruction.',
                ]],
                'allergies' => [],
                'immunizations' => [],
                'medications' => [[
                    'record_key' => 'doc-med-fluticasone',
                    'name' => 'Fluticasone',
                    'dose' => '50 mcg',
                    'route' => 'nasal',
                    'frequency' => 'daily',
                    'status' => 'active',
                    'raw_text' => 'Use fluticasone daily.',
                ]],
                'vitals' => [],
                'lab_results' => [],
                'procedures' => [],
                'encounters' => [],
                'portal_messages' => [[
                    'record_key' => 'doc-message-care-team',
                    'message_at' => '2021-11-13 09:15:00',
                    'direction' => 'inbound',
                    'subject' => 'Care team message',
                    'sender_name' => 'Care Team',
                    'recipient_name' => 'Patient',
                    'summary' => 'Follow-up instructions were sent.',
                    'clinical_relevance' => 'Care coordination',
                    'source_refs' => ['page 4'],
                    'raw_text' => 'Message from care team.',
                ]],
                'negative_assertions' => [[
                    'record_key' => 'doc-negative-no-known-allergies',
                    'assertion_type' => 'allergy_absence',
                    'statement' => 'No known allergies documented.',
                    'scope' => 'allergies',
                    'observed_on' => '2021-11-13',
                    'source_refs' => ['page 2'],
                    'raw_text' => 'No Known Allergies.',
                ]],
            ],
        ];
    }
}
