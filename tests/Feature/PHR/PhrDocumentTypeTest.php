<?php

namespace Tests\Feature\PHR;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Documents\SignedDocumentUploadService;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\InvalidPhrDocumentType;
use App\Support\PHR\PhrDocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\EhiDocumentFixtures;
use Tests\TestCase;

/**
 * Every persisted `phr_documents.document_type` is one of
 * PhrDocument::DOCUMENT_TYPES.
 *
 * The model rejects an unknown value on create and on change, and a writer that
 * names no type stores `other`. These tests drive each place that writes a
 * document and check what it stores:
 *
 * - manual upload (browser)                    validated by the request
 * - agent upload (REST, and MCP through REST)  validated by the request
 * - presigned upload finalize                  fixed map from the upload kind
 * - GenAI import of an uploaded file           normalized
 * - GenAI result applied to a stored document  normalized
 * - GenAI / CLI structured `phr_document`      normalized
 * - FHIR DocumentReference                     normalized, `other` when absent
 * - CLI PDF extraction (`phr:import:pdf`)      normalized
 * - EHI Tables proposal apply                  checked against the list
 * - a raw insert that omits the column         the column default, `other`
 * - native-backup restore                      see PhrNativeRestoreTest, which
 *   maps an archived legacy value instead of refusing the whole restore.
 */
final class PhrDocumentTypeTest extends TestCase
{
    use ConfiguresPassportKeys;
    use EhiDocumentFixtures;

    public function test_every_allowed_type_can_be_stored(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        foreach (PhrDocument::DOCUMENT_TYPES as $type) {
            $document = $this->createDocument($owner, $patient, ['document_type' => $type]);

            $this->assertSame($type, $document->fresh()?->document_type);
        }
    }

    public function test_a_writer_that_names_no_type_stores_other(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        $document = PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            'source' => 'manual_upload',
        ]);

        $this->assertSame('other', $document->fresh()?->document_type);
    }

    public function test_a_raw_insert_that_omits_the_type_gets_an_allowed_column_default(): void
    {
        // The column was created defaulting to `general`, outside the list.
        [$owner, $patient] = $this->ownerAndPatient();
        $id = DB::table('phr_documents')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            'source' => 'manual_upload',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('other', DB::table('phr_documents')->where('id', $id)->value('document_type'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTypes(): iterable
    {
        yield 'old column default' => ['general'];
        yield 'fhir fallback literal' => ['document_reference'];
        yield 'free text' => ['Explanation of Benefits'];
        yield 'differently cased' => ['Lab_Report'];
        yield 'empty string' => [''];
        yield 'null' => [null];
    }

    #[DataProvider('invalidTypes')]
    public function test_an_unknown_type_is_rejected_before_it_is_stored(mixed $type): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        try {
            $this->createDocument($owner, $patient, ['document_type' => $type]);
            $this->fail('An unknown document type was stored.');
        } catch (InvalidPhrDocumentType $exception) {
            $this->assertStringContainsString('lab_report', $exception->getMessage());
        }

        $this->assertDatabaseCount('phr_documents', 0);
    }

    public function test_changing_a_document_to_an_unknown_type_is_rejected(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $document = $this->createDocument($owner, $patient, ['document_type' => 'other']);

        try {
            $document->update(['document_type' => 'general']);
            $this->fail('A document was changed to an unknown type.');
        } catch (InvalidPhrDocumentType) {
        }

        $this->assertSame('other', $document->fresh()?->document_type);
    }

    public function test_a_stored_legacy_type_does_not_block_an_unrelated_edit(): void
    {
        // A row written before the rule (or by a raw insert) must not turn a
        // metadata edit into a server error.
        [$owner, $patient] = $this->ownerAndPatient();
        $id = DB::table('phr_documents')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'document_type' => 'general',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            'source' => 'manual_upload',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PhrDocument::query()->findOrFail($id)->update(['title' => 'Renamed']);

        $this->assertSame('Renamed', PhrDocument::query()->findOrFail($id)->title);
    }

    public function test_normalize_keeps_allowed_values_and_maps_every_alias_into_the_list(): void
    {
        foreach (PhrDocument::DOCUMENT_TYPES as $type) {
            $this->assertSame($type, PhrDocumentType::normalize($type));
        }
        foreach (PhrDocumentType::LEGACY_ALIASES as $alias => $target) {
            $this->assertContains($target, PhrDocument::DOCUMENT_TYPES, $alias);
            $this->assertSame($target, PhrDocumentType::normalize($alias));
        }
        $this->assertSame('lab_report', PhrDocumentType::normalize(' Lab Report '));
        $this->assertSame('office_visit_note', PhrDocumentType::normalize('Office-Visit-Note'));
        $this->assertSame('other', PhrDocumentType::normalize('something_else'));
        $this->assertSame('other', PhrDocumentType::normalize(null));
        $this->assertSame('other', PhrDocumentType::normalize(['lab_report']));
    }

    public function test_manual_upload_rejects_an_unknown_type_and_stores_an_allowed_one(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        [$owner, $patient] = $this->ownerAndPatient();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', '%PDF-1.4 synthetic'),
            'document_type' => 'general',
        ])->assertUnprocessable()->assertJsonValidationErrors('document_type');

        $this->actingAs($owner)->post("/api/phr/patients/{$patient->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', '%PDF-1.4 synthetic'),
            'document_type' => 'lab_report',
        ])->assertCreated()->assertJsonPath('document.document_type', 'lab_report');

        $this->assertSoleType($patient, 'lab_report');
    }

    public function test_metadata_edit_rejects_an_unknown_type(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $document = $this->createDocument($owner, $patient, ['document_type' => 'other']);

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patient->id}/documents/{$document->id}", [
            'document_type' => 'general',
        ])->assertUnprocessable()->assertJsonValidationErrors('document_type');

        $this->assertSoleType($patient, 'other');
    }

    public function test_agent_upload_rejects_an_unknown_type_and_stores_an_allowed_one(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->configurePassportKeys();
        [$owner, $patient] = $this->ownerAndPatient();
        $client = Client::query()->create([
            'name' => 'Synthetic Document Writer',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($owner, [AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::DOCUMENTS_WRITE], 'api', $client);

        $this->post("/api/v1/patients/{$patient->id}/documents", [
            'external_id' => 'synthetic-type-001',
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', '%PDF-1.4 synthetic agent'),
            'document_type' => 'document_reference',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('phr_documents', 0);

        $this->post("/api/v1/patients/{$patient->id}/documents", [
            'external_id' => 'synthetic-type-001',
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', '%PDF-1.4 synthetic agent'),
            'document_type' => 'prescription',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.document_type', 'prescription');

        $this->assertSoleType($patient, 'prescription');
    }

    public function test_presigned_upload_kinds_map_only_to_allowed_types(): void
    {
        $map = (new ReflectionClassConstant(SignedDocumentUploadService::class, 'DOCUMENT_TYPES'))->getValue();
        $this->assertIsArray($map);
        $this->assertNotEmpty($map);

        foreach ($map as $kind => $type) {
            $this->assertContains($type, PhrDocument::DOCUMENT_TYPES, (string) $kind);
        }
    }

    public function test_genai_import_of_an_uploaded_file_stores_a_normalized_type(): void
    {
        [$owner, $patient, $job] = $this->genAiJob();

        app(PhrStructuredDataImporter::class)->storeGenAiDocument($patient, $owner->id, $job, [
            'title' => 'Synthetic',
            'document_type' => 'Imaging Report',
        ]);

        $this->assertSoleType($patient, 'imaging_report');
    }

    public function test_genai_result_applied_to_a_stored_document_stores_a_normalized_type(): void
    {
        [$owner, $patient, $job] = $this->genAiJob();
        $document = $this->createDocument($owner, $patient, ['document_type' => 'lab_report']);

        app(PhrStructuredDataImporter::class)->updateDocumentFromGenAiResult($document, $job, [
            'document_type' => 'general',
        ]);

        $this->assertSoleType($patient, 'other');
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function structuredDocumentTypes(): iterable
    {
        yield 'allowed type is kept' => ['consent', 'consent'];
        yield 'old column default' => ['general', 'other'];
        yield 'differently cased' => ['DISCHARGE_SUMMARY', 'discharge_summary'];
        yield 'free text' => ['Explanation of Benefits', 'other'];
        yield 'absent' => [null, 'other'];
    }

    #[DataProvider('structuredDocumentTypes')]
    public function test_structured_document_import_stores_an_allowed_type(mixed $type, string $expected): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        app(PhrStructuredDataImporter::class)->importPayload($patient, $owner->id, 'phr_document', [
            'external_id' => 'synthetic-structured-doc',
            'title' => 'Synthetic structured document',
            'document_type' => $type,
        ], ['import_source' => 'genai', 'source' => 'genai_import']);

        $this->assertSoleType($patient, $expected);
    }

    /** @return iterable<string, array{array<string, mixed>|null, string}> */
    public static function fhirDocumentTypes(): iterable
    {
        yield 'no type' => [null, 'other'];
        yield 'type text naming an allowed type' => [['text' => 'Lab Report'], 'lab_report'];
        yield 'type text outside the list' => [['text' => 'Synthetic note kind'], 'other'];
    }

    /** @param array<string, mixed>|null $type */
    #[DataProvider('fhirDocumentTypes')]
    public function test_fhir_document_reference_stores_an_allowed_type(?array $type, string $expected): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $path = $this->tempFile(json_encode([
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'entry' => [[
                'resource' => array_filter([
                    'resourceType' => 'DocumentReference',
                    'id' => 'doc-1',
                    'type' => $type,
                    'description' => 'Synthetic note',
                    'content' => [['attachment' => ['contentType' => 'text/plain', 'title' => 'Synthetic note']]],
                ], static fn (mixed $value): bool => $value !== null),
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('phr:import:fhir', ['--patient' => $patient->id, '--actor' => $owner->id, '--file' => $path])
            ->assertExitCode(0);

        $this->assertSoleType($patient, $expected);
    }

    public function test_cli_pdf_extraction_stores_a_normalized_type(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        [$owner, $patient] = $this->ownerAndPatient();
        $pdf = $this->tempFile('%PDF-1.4 synthetic cli');
        $json = $this->tempFile(json_encode(['document_type' => 'Prior Authorization'], JSON_THROW_ON_ERROR));

        $this->artisan('phr:import:pdf', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--file' => $pdf,
            '--type' => 'phr_document',
            '--json' => $json,
        ])->assertExitCode(0);

        $this->assertSame(
            ['prior_authorization'],
            PhrDocument::query()->where('patient_id', $patient->id)->distinct()->pluck('document_type')->all(),
        );
    }

    public function test_ehi_tables_apply_stores_an_allowed_type(): void
    {
        $this->setUpEhiDocuments();
        $this->ehiDocOwnedNote('N900', '9900');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N900', 'NOTE_TEXT' => 'synthetic body']);

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N900');
        $document = PhrDocument::query()->findOrFail($this->ehiDocApply($this->ehiDocPersist($draft))->recordId);

        $this->assertContains($document->document_type, PhrDocument::DOCUMENT_TYPES);
    }

    /** @return array{User, PhrPatient, GenAiImportJob} */
    private function genAiJob(): array
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        Storage::fake('s3');
        Storage::disk('s3')->put('genai-import/synthetic.pdf', '%PDF-1.4 synthetic genai');
        [$owner, $patient] = $this->ownerAndPatient();
        $job = GenAiImportJob::query()->create([
            'user_id' => $owner->id,
            'job_type' => 'phr_document',
            'file_hash' => hash('sha256', 'synthetic genai'),
            'original_filename' => 'synthetic.pdf',
            's3_path' => 'genai-import/synthetic.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 24,
            'context_json' => json_encode(['patient_id' => $patient->id], JSON_THROW_ON_ERROR),
            'status' => 'parsed',
        ]);

        return [$owner, $patient, $job];
    }

    /** @return array{User, PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Type Patient',
            'relationship' => 'self',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        return [$owner, $patient];
    }

    /** @param array<string, mixed> $attributes */
    private function createDocument(User $owner, PhrPatient $patient, array $attributes): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'source' => 'manual_upload',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            ...$attributes,
        ]);
    }

    private function assertSoleType(PhrPatient $patient, string $expected): void
    {
        $this->assertSame([$expected], PhrDocument::query()->where('patient_id', $patient->id)->pluck('document_type')->all());
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phr-type-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
