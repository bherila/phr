<?php

namespace Tests\Feature;

use App\GenAiProcessor\Jobs\ParseImportJob;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Jobs\PHR\IntakeEhiUploadJob;
use App\Models\AgentApiAudit;
use App\Models\PhrCondition;
use App\Models\PhrDocument;
use App\Models\PhrEhiUpload;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use App\Services\PHR\Import\PhrStructuredDocumentImporter;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Mcp\Capability\Discovery\SchemaValidator;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;
use ZipArchive;

final class AgentApiStructuredImportTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
        Storage::fake('s3');
        Queue::fake();
    }

    public function test_document_import_creation_is_idempotent_bounded_and_patient_scoped(): void
    {
        $owner = $this->user('import-owner@example.test');
        $other = $this->user('import-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Import Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Import Patient');
        $document = $this->document($patient, $owner, 'synthetic-source.pdf');
        $hiddenDocument = $this->document($hiddenPatient, $other, 'synthetic-hidden.pdf');
        $client = $this->client('Synthetic Import Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $created = $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertAccepted()
            ->assertJsonPath('resource_type', 'import_job')
            ->assertJsonPath('patient_id', $patient->id)
            ->assertJsonPath('outcome', 'created')
            ->assertJsonPath('data.document_id', $document->id)
            ->assertJsonPath('data.status', 'pending')
            ->json();

        $job = GenAiImportJob::query()->sole();
        $this->assertSame($job->id, $created['data']['id']);
        $this->assertSame($job->id, $document->refresh()->genai_job_id);
        Storage::disk('s3')->assertExists($job->s3_path);
        Queue::assertPushed(ParseImportJob::class, 1);

        $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.id', $job->id);
        $this->assertDatabaseCount('genai_import_jobs', 1);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
        Queue::assertPushed(ParseImportJob::class, 1);

        $this->postJson("/api/v1/patients/{$hiddenPatient->id}/imports", [
            'document_id' => $hiddenDocument->id,
        ])->assertNotFound();

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports?limit=1&status=pending")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('pagination.limit', 1);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.document_id', $document->id)
            ->assertJsonPath('data.results', []);
        $this->getJson("/api/v1/patients/{$hiddenPatient->id}/imports/{$job->id}")->assertNotFound();

        $audit = json_encode(AgentApiAudit::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-source.pdf', $audit);
        $this->assertStringNotContainsString($job->s3_path, $audit);
    }

    public function test_a_ccda_document_is_imported_by_phrs_parser_not_queued_for_ai(): void
    {
        $owner = $this->user('ccda-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic C-CDA Patient');
        $document = $this->storedDocument($patient, $owner, 'visit-summary.xml', 'application/xml', PhrCcdaSyntheticDocument::xml());
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic C-CDA Client'));

        $first = $this->postJson("/api/v1/patients/{$patient->id}/imports", ['document_id' => $document->id])->assertOk()
            ->assertJsonPath('resource_type', 'structured_import')
            ->assertJsonPath('outcome', 'imported')
            ->assertJsonPath('data.document_id', $document->id)
            ->assertJsonPath('data.format', 'ccda')
            ->assertJsonPath('data.upload', null);
        $this->assertConformsToImportCreate($first->json());
        $created = (int) $first->json('data.import.created');
        $this->assertGreaterThan(0, $created);
        $this->assertSame(0, PhrImmunization::query()->where('patient_id', $patient->id)->where('review_status', '!=', PhrReviewStatus::PENDING)->count());
        $this->assertDatabaseCount('genai_import_jobs', 0);
        Queue::assertNotPushed(ParseImportJob::class);

        // A repeat is idempotent: nothing the document holds changed.
        $this->postJson("/api/v1/patients/{$patient->id}/imports", ['document_id' => $document->id])->assertOk()
            ->assertJsonPath('data.import.created', 0)
            ->assertJsonPath('data.import.unchanged', $created);
    }

    public function test_a_structured_document_already_sent_to_ai_keeps_that_import(): void
    {
        $owner = $this->user('ccda-ai-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Earlier AI Patient');
        $document = $this->storedDocument($patient, $owner, 'visit-summary.xml', 'application/xml', PhrCcdaSyntheticDocument::xml());
        $job = $this->linkedJob($document, $owner, 'parsed');
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic Earlier AI Client'));

        $this->postJson("/api/v1/patients/{$patient->id}/imports", ['document_id' => $document->id])->assertOk()
            ->assertJsonPath('resource_type', 'import_job')
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.id', $job->id);
        $this->assertSame(0, PhrImmunization::query()->count(), 'not imported a second time beside the AI proposals');
    }

    public function test_a_structured_document_too_large_to_import_directly_is_refused_not_sent_to_ai(): void
    {
        $owner = $this->user('large-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Large Patient');
        $document = $this->storedDocument($patient, $owner, 'huge-summary.xml', 'application/xml', PhrCcdaSyntheticDocument::xml());
        // The recorded size is what decides; the stored bytes stay small for the test.
        $document->forceFill(['byte_size' => PhrStructuredDocumentImporter::MAX_DIRECT_BYTES + 1])->save();
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic Large Client'));

        $this->postJson("/api/v1/patients/{$patient->id}/imports", ['document_id' => $document->id])->assertStatus(422);

        $this->assertDatabaseCount('genai_import_jobs', 0);
        $this->assertSame(0, PhrImmunization::query()->count());
    }

    public function test_a_structured_document_naming_two_people_is_refused_not_sent_to_ai(): void
    {
        $owner = $this->user('family-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Family Patient');
        $document = $this->storedDocument($patient, $owner, 'family.json', 'application/json', (string) json_encode(['resourceType' => 'Bundle', 'entry' => [
            ['resource' => ['resourceType' => 'Patient', 'id' => 'holder', 'birthDate' => '1984-03-09']],
            ['resource' => ['resourceType' => 'Patient', 'id' => 'dependent', 'birthDate' => '2015-07-01']],
            ['resource' => ['resourceType' => 'Condition', 'id' => 'c-1', 'code' => ['text' => 'Synthetic condition']]],
        ]]));
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic Family Client'));

        $this->postJson("/api/v1/patients/{$patient->id}/imports", ['document_id' => $document->id])->assertStatus(422);

        $this->assertSame(0, PhrCondition::query()->count());
        $this->assertDatabaseCount('genai_import_jobs', 0);
    }

    public function test_a_zip_document_is_staged_once_for_intake_and_reports_what_it_became(): void
    {
        $owner = $this->user('zip-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic ZIP Patient');
        $document = $this->storedDocument($patient, $owner, 'mychart.zip', 'application/zip', $this->myChartZip());
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic ZIP Client'));
        $url = "/api/v1/patients/{$patient->id}/imports";

        $queued = $this->postJson($url, ['document_id' => $document->id])->assertAccepted()
            ->assertJsonPath('resource_type', 'structured_import')
            ->assertJsonPath('outcome', 'queued')
            ->assertJsonPath('data.format', 'zip')
            ->assertJsonPath('data.import', null)
            ->assertJsonPath('data.upload.status', PhrEhiUpload::STATUS_QUEUED);
        $this->assertConformsToImportCreate($queued->json());
        $uploadId = (int) $queued->json('data.upload.id');
        Queue::assertPushed(IntakeEhiUploadJob::class, 1);
        // Staged by a hard link, not a copy made while the patient lock is held.
        $disk = Storage::disk(PhrDocument::STORAGE_DISK);
        $this->assertSame(
            fileinode($disk->path((string) $document->storage_path)),
            fileinode($disk->path((string) PhrEhiUpload::query()->findOrFail($uploadId)->storage_path)),
        );

        $this->postJson($url, ['document_id' => $document->id])->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('data.upload.id', $uploadId);
        Queue::assertPushed(IntakeEhiUploadJob::class, 1);
        $this->assertSame(1, PhrEhiUpload::query()->where('source_document_id', $document->id)->count());

        (new IntakeEhiUploadJob($uploadId))->handle(app(EhiUploadService::class));
        $done = $this->postJson($url, ['document_id' => $document->id])->assertOk()
            ->assertJsonPath('data.upload.status', PhrEhiUpload::STATUS_COMPLETED)
            ->assertJsonPath('data.upload.format', PhrEhiUpload::FORMAT_MYCHART);
        $this->assertConformsToImportCreate($done->json());
        $this->assertSame(PhrReviewStatus::PENDING, PhrCondition::query()->where('patient_id', $patient->id)->sole()->review_status);
        $this->assertDatabaseCount('genai_import_jobs', 0);
    }

    public function test_a_failed_zip_upload_is_staged_afresh_when_its_copy_is_gone(): void
    {
        $owner = $this->user('retry-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Retry Patient');
        $document = $this->storedDocument($patient, $owner, 'mychart.zip', 'application/zip', $this->myChartZip());
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic Retry Client'));
        $url = "/api/v1/patients/{$patient->id}/imports";
        $first = (int) $this->postJson($url, ['document_id' => $document->id])->assertAccepted()->json('data.upload.id');

        // Purged after it failed: no copy left to queue again.
        PhrEhiUpload::query()->whereKey($first)->update(['status' => PhrEhiUpload::STATUS_FAILED, 'failure_reason' => 'upload_expired', 'storage_path' => null]);
        $second = (int) $this->postJson($url, ['document_id' => $document->id])->assertAccepted()
            ->assertJsonPath('outcome', 'queued')->json('data.upload.id');
        $this->assertNotSame($first, $second);

        // Failed while its copy vanished from storage: staged afresh, not re-queued to fail again.
        $staged = (string) PhrEhiUpload::query()->findOrFail($second)->storage_path;
        Storage::disk((string) config('phr.ehi_disk'))->delete($staged);
        PhrEhiUpload::query()->whereKey($second)->update(['status' => PhrEhiUpload::STATUS_FAILED, 'failure_reason' => 'upload_expired']);
        $third = (int) $this->postJson($url, ['document_id' => $document->id])->assertAccepted()->json('data.upload.id');
        $this->assertNotContains($third, [$first, $second]);
        Queue::assertPushed(IntakeEhiUploadJob::class, 3);
    }

    public function test_a_zip_that_is_not_an_export_still_goes_to_ai(): void
    {
        $owner = $this->user('docx-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic DOCX Patient');
        $zipPath = tempnam(sys_get_temp_dir(), 'phr-agent-docx-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::OVERWRITE));
        // A word-processing file is a ZIP of XML, none of it clinical.
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $zip->close();
        $document = $this->storedDocument($patient, $owner, 'letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', (string) file_get_contents($zipPath));
        @unlink($zipPath);
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic DOCX Client'));

        $this->postJson("/api/v1/patients/{$patient->id}/imports", ['document_id' => $document->id])->assertAccepted()
            ->assertJsonPath('resource_type', 'import_job')
            ->assertJsonPath('outcome', 'created');
        $this->assertSame(0, PhrEhiUpload::query()->count());
        Queue::assertPushed(ParseImportJob::class, 1);
    }

    public function test_a_zip_document_for_an_archived_patient_is_refused(): void
    {
        $owner = $this->user('archived-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Archived Patient');
        $document = $this->storedDocument($patient, $owner, 'export.zip', 'application/zip', $this->myChartZip());
        $patient->update(['archived_at' => now()]);
        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $this->client('Synthetic Archived Client'));

        $this->postJson("/api/v1/patients/{$patient->id}/imports", ['document_id' => $document->id])->assertConflict();
        $this->assertSame(0, PhrEhiUpload::query()->count());
    }

    public function test_import_review_is_atomic_idempotent_scope_separated_and_cross_job_safe(): void
    {
        $owner = $this->user('review-owner@example.test');
        $manager = $this->user('review-manager@example.test');
        $patient = $this->patient($owner, 'Synthetic Review Patient');
        $this->grant($patient, $owner, $manager, PhrPatientUserAccess::LEVEL_MANAGER);
        $document = $this->document($patient, $owner, 'synthetic-review.pdf');
        $job = $this->linkedJob($document, $owner, 'parsed');
        $accepted = $this->proposal($job, 0, [
            'external_id' => 'synthetic-lab-proposal',
            'test_name' => 'Synthetic panel',
            'analyte' => 'Synthetic analyte',
            'value' => '1.0',
        ]);
        $rejected = $this->proposal($job, 1, ['analyte' => 'Synthetic rejected analyte']);
        $otherDocument = $this->document($patient, $owner, 'synthetic-other-review.pdf');
        $otherJob = $this->linkedJob($otherDocument, $owner, 'parsed');
        $otherResult = $this->proposal($otherJob, 0, ['analyte' => 'Synthetic other analyte']);
        $client = $this->client('Synthetic Review Client');

        Passport::actingAs($manager, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $response = $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$accepted->id}/review", [
            'action' => 'accept',
        ])->assertOk()
            ->assertJsonPath('outcome', 'accepted')
            ->assertJsonPath('import.created', 1)
            ->assertJsonPath('import.unchanged', 0)
            ->assertJsonPath('data.id', $accepted->id)
            ->assertJsonPath('data.status', 'imported')
            ->assertJsonCount(2, 'data');
        $response->assertJsonMissingPath('data.data');
        $this->assertDatabaseCount('phr_lab_results', 1);
        $this->assertSame($document->id, PhrLabResult::query()->sole()->source_document_id);
        $this->assertSame('parsed', $job->refresh()->status);

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$accepted->id}/review", [
            'action' => 'accept',
        ])->assertOk()
            ->assertJsonPath('outcome', 'unchanged')
            ->assertJsonPath('import.created', 0);
        $this->assertDatabaseCount('phr_lab_results', 1);

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$rejected->id}/review", [
            'action' => 'reject',
        ])->assertOk()->assertJsonPath('outcome', 'rejected');
        $this->assertSame('imported', $job->refresh()->status);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$rejected->id}/review", [
            'action' => 'reject',
        ])->assertOk()->assertJsonPath('outcome', 'unchanged');

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/results/{$otherResult->id}/review", [
            'action' => 'reject',
        ])->assertNotFound();
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$otherJob->id}/results/{$otherResult->id}/review", [
            'action' => 'reject',
            'payload' => ['not' => 'allowed'],
        ])->assertUnprocessable();
    }

    public function test_import_routes_enforce_independent_scopes_and_writable_grants(): void
    {
        $owner = $this->user('import-auth-owner@example.test');
        $viewer = $this->user('import-auth-viewer@example.test');
        $other = $this->user('import-auth-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Import Authorization Patient');
        $hiddenPatient = $this->patient($other, 'Synthetic Hidden Authorization Patient');
        $this->grant($patient, $owner, $viewer, PhrPatientUserAccess::LEVEL_VIEWER);
        $document = $this->document($patient, $owner, 'synthetic-authorization.pdf');
        $client = $this->client('Synthetic Import Authorization Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports")->assertForbidden();

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertForbidden();
        $this->getJson("/api/v1/patients/{$hiddenPatient->id}/imports")->assertNotFound();

        Passport::actingAs($viewer, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports", [
            'document_id' => $document->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('genai_import_jobs', 0);
    }

    public function test_import_reads_fail_closed_when_job_context_disagrees_with_document_ownership(): void
    {
        $owner = $this->user('mismatched-import-owner@example.test');
        $other = $this->user('mismatched-import-other@example.test');
        $patient = $this->patient($owner, 'Synthetic Mismatched Import Patient');
        $otherPatient = $this->patient($other, 'Synthetic Mismatched Other Patient');
        $document = $this->document($patient, $owner, 'synthetic-mismatched.pdf');
        $job = $this->linkedJob($document, $owner, 'parsed');
        $job->update(['context_json' => json_encode(['patient_id' => $otherPatient->id], JSON_THROW_ON_ERROR)]);
        $client = $this->client('Synthetic Mismatched Import Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports")
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")->assertNotFound();
    }

    public function test_failed_import_retry_clears_unreviewed_output_and_dispatches_once(): void
    {
        $owner = $this->user('retry-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Retry Patient');
        $document = $this->document($patient, $owner, 'synthetic-retry.pdf');
        $job = $this->linkedJob($document, $owner, 'failed', [
            'retry_count' => 1,
            'error_message' => 'Synthetic private provider failure.',
            'raw_response' => 'Synthetic private raw response.',
        ]);
        $missingStagingPath = $job->s3_path;
        $proposal = $this->proposal($job, 0, ['analyte' => 'Synthetic stale proposal']);
        $client = $this->client('Synthetic Retry Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.failure_code', 'processing_failed')
            ->assertJsonPath('data.can_retry', true)
            ->assertJsonMissing(['Synthetic private provider failure.', 'Synthetic private raw response.']);

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('outcome', 'retried')
            ->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseMissing('genai_import_results', ['id' => $proposal->id]);
        $job->refresh();
        $this->assertNull($job->error_message);
        $this->assertNull($job->raw_response);
        $this->assertNotSame($missingStagingPath, $job->s3_path);
        Storage::disk('s3')->assertExists($job->s3_path);
        Queue::assertPushed(ParseImportJob::class, 1);

        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');
        Queue::assertPushed(ParseImportJob::class, 1);

        $job->update(['status' => 'failed', 'retry_count' => GenAiImportJob::MAX_RETRIES]);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")->assertConflict();
    }

    public function test_failed_import_with_a_reviewed_result_cannot_be_retried(): void
    {
        $owner = $this->user('reviewed-retry-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Reviewed Retry Patient');
        $document = $this->document($patient, $owner, 'synthetic-reviewed-retry.pdf');
        $job = $this->linkedJob($document, $owner, 'failed', ['retry_count' => 1]);
        $reviewed = $this->proposal($job, 0, ['analyte' => 'Synthetic reviewed proposal']);
        $reviewed->markSkipped();
        $client = $this->client('Synthetic Reviewed Retry Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.can_retry', false);

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")->assertConflict();

        $this->assertDatabaseHas('genai_import_results', [
            'id' => $reviewed->id,
            'status' => 'skipped',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_failed_import_for_a_deleted_document_cannot_be_retried(): void
    {
        $owner = $this->user('deleted-retry-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Deleted Retry Patient');
        $document = $this->document($patient, $owner, 'synthetic-deleted-retry.pdf');
        $job = $this->linkedJob($document, $owner, 'failed', ['retry_count' => 1]);
        $document->delete();
        $client = $this->client('Synthetic Deleted Retry Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.can_retry', false);

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")->assertNotFound();

        $this->assertSame('failed', $job->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_retry_transfers_provider_ownership_and_staging_to_the_current_actor(): void
    {
        $owner = $this->user('retry-transfer-owner@example.test');
        $manager = $this->user('retry-transfer-manager@example.test');
        $patient = $this->patient($owner, 'Synthetic Retry Transfer Patient');
        $this->grant($patient, $owner, $manager, PhrPatientUserAccess::LEVEL_MANAGER);
        $document = $this->document($patient, $owner, 'synthetic-retry-transfer.pdf');
        $job = $this->linkedJob($document, $owner, 'failed', [
            'retry_count' => 1,
            'ai_provider' => 'synthetic-old-provider',
            'ai_model' => 'synthetic-old-model',
        ]);
        $oldStagingPath = $job->s3_path;
        Storage::disk('s3')->put($oldStagingPath, 'Synthetic old staging bytes');
        $client = $this->client('Synthetic Retry Transfer Client');

        Passport::actingAs($manager, [AgentApiScopes::IMPORTS_WRITE], 'api', $client);
        $this->postJson("/api/v1/patients/{$patient->id}/imports/{$job->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('outcome', 'retried');

        $job->refresh();
        $this->assertSame($manager->id, $job->user_id);
        $this->assertNotSame($oldStagingPath, $job->s3_path);
        $this->assertNull($job->ai_provider);
        $this->assertNull($job->ai_model);
        Storage::disk('s3')->assertMissing($oldStagingPath);
        Storage::disk('s3')->assertExists($job->s3_path);
        Queue::assertPushed(ParseImportJob::class, 1);
    }

    public function test_scalar_import_proposal_does_not_break_other_results(): void
    {
        $owner = $this->user('scalar-result-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Scalar Result Patient');
        $document = $this->document($patient, $owner, 'synthetic-scalar-result.pdf');
        $job = $this->linkedJob($document, $owner, 'parsed');
        GenAiImportResult::query()->create([
            'job_id' => $job->id,
            'result_index' => 0,
            'result_json' => json_encode('Synthetic malformed proposal', JSON_THROW_ON_ERROR),
            'status' => 'pending_review',
        ]);
        $this->proposal($job, 1, ['analyte' => 'Synthetic valid proposal']);
        $client = $this->client('Synthetic Scalar Result Client');

        Passport::actingAs($owner, [AgentApiScopes::IMPORTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/imports/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.results.0.data', [])
            ->assertJsonPath('data.results.1.data.analyte', 'Synthetic valid proposal');
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Import User',
            'email' => $email,
            'user_role' => 'user',
        ]);
    }

    private function patient(User $owner, string $displayName): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $displayName,
            'relationship' => 'self',
        ]);
        $this->grant($patient, $owner, $owner, PhrPatientUserAccess::LEVEL_OWNER);

        return $patient;
    }

    private function grant(PhrPatient $patient, User $granter, User $user, string $level): void
    {
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $level,
            'granted_by_user_id' => $granter->id,
            'granted_at' => now(),
        ]);
    }

    private function document(PhrPatient $patient, User $owner, string $filename): PhrDocument
    {
        $path = "patients/{$patient->id}/documents/synthetic/{$filename}";
        $contents = '%PDF-1.4 synthetic import document '.$filename;
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $contents);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic import document',
            'document_type' => 'lab_report',
            'original_filename' => $filename,
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($contents),
            'file_hash' => hash('sha256', $contents),
            'source' => 'manual_upload',
        ]);
    }

    private function storedDocument(PhrPatient $patient, User $owner, string $filename, string $mimeType, string $contents): PhrDocument
    {
        $path = "patients/{$patient->id}/documents/synthetic/{$filename}";
        Storage::disk(PhrDocument::STORAGE_DISK)->put($path, $contents);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
            'title' => 'Synthetic structured document',
            'document_type' => 'other',
            'original_filename' => $filename,
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $path,
            'mime_type' => $mimeType,
            'byte_size' => strlen($contents),
            'file_hash' => hash('sha256', $contents),
            'source' => 'manual_upload',
        ]);
    }

    /** A MyChart record ZIP's bytes: one FHIR bundle with one condition. */
    private function myChartZip(): string
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'phr-agent-mychart-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::OVERWRITE));
        $zip->addFromString('Records/bundle.json', (string) json_encode(['resourceType' => 'Bundle', 'entry' => [
            ['resource' => ['resourceType' => 'Condition', 'id' => 'zip-condition', 'code' => ['text' => 'Synthetic ZIP condition']]],
        ]]));
        $zip->close();
        $bytes = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return $bytes;
    }

    /** @param array<string, mixed> $payload */
    private function assertConformsToImportCreate(array $payload): void
    {
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($payload, AgentApiResponseSchemaCatalog::forOperation('imports.create')));
    }

    /** @param array<string, mixed> $attributes */
    private function linkedJob(PhrDocument $document, User $actor, string $status, array $attributes = []): GenAiImportJob
    {
        $job = GenAiImportJob::query()->create([
            'user_id' => $actor->id,
            'job_type' => 'phr_lab_result',
            'file_hash' => $document->file_hash,
            'original_filename' => $document->original_filename,
            's3_path' => 'genai-import/synthetic/'.$document->id,
            'mime_type' => $document->mime_type,
            'file_size_bytes' => $document->byte_size,
            'context_json' => json_encode([
                'patient_id' => $document->patient_id,
                'document_id' => $document->id,
            ], JSON_THROW_ON_ERROR),
            'status' => $status,
            ...$attributes,
        ]);
        $document->update(['genai_job_id' => $job->id]);

        return $job;
    }

    /** @param array<string, mixed> $payload */
    private function proposal(GenAiImportJob $job, int $index, array $payload): GenAiImportResult
    {
        return GenAiImportResult::query()->create([
            'job_id' => $job->id,
            'result_index' => $index,
            'result_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending_review',
            'produced_by' => 'synthetic',
        ]);
    }

    private function client(string $name): Client
    {
        return Client::query()->create([
            'name' => $name,
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }
}
