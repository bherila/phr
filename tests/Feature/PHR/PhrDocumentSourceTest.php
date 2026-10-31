<?php

namespace Tests\Feature\PHR;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\InvalidPhrDocumentSource;
use App\Support\PHR\PhrDocumentSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\EhiDocumentFixtures;
use Tests\TestCase;

/**
 * Every persisted `phr_documents.source` is one of PhrDocument::SOURCES.
 *
 * The browser parses `source` against that list, so one stray value made the
 * document viewer fail instead of rendering. The model rejects an unknown value
 * on save; these tests drive each place that writes a document and check what
 * it stores:
 *
 * - manual upload (browser)                    manual_upload
 * - agent upload (REST, and MCP through REST)  agent_upload
 * - GenAI import of an uploaded file           genai_import
 * - GenAI / CLI structured `phr_document`      by alias (genai, fhir, ccda, mychart)
 * - FHIR DocumentReference                     fhir_import
 * - CLI PDF extraction (`phr:import:pdf`)      manual_upload
 * - EHI Tables proposal apply                  ehi_tables
 * - native-backup restore                      see PhrNativeRestoreTest, which
 *   maps an archived legacy value instead of refusing the whole restore.
 *
 * No C-CDA or MyChart importer creates a document row: the file is stored by an
 * upload first, so it is a manual_upload. Their aliases are covered through the
 * structured `phr_document` path, which is how such a row would be written.
 */
final class PhrDocumentSourceTest extends TestCase
{
    use ConfiguresPassportKeys;
    use EhiDocumentFixtures;

    public function test_every_allowed_source_can_be_stored(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        foreach (PhrDocument::SOURCES as $source) {
            $document = $this->createDocument($owner, $patient, ['source' => $source]);

            $this->assertSame($source, $document->fresh()?->source);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSources(): iterable
    {
        yield 'legacy free text' => ['onedrive_manual_review'];
        yield 'retired cli alias' => ['cli_pdf'];
        yield 'unnormalized fhir alias' => ['fhir'];
        yield 'empty string' => [''];
        yield 'null' => [null];
    }

    #[DataProvider('invalidSources')]
    public function test_an_unknown_source_is_rejected_before_it_is_stored(mixed $source): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        try {
            $this->createDocument($owner, $patient, ['source' => $source]);
            $this->fail('An unknown document source was stored.');
        } catch (InvalidPhrDocumentSource $exception) {
            $this->assertStringContainsString('manual_upload', $exception->getMessage());
        }

        $this->assertDatabaseCount('phr_documents', 0);
    }

    public function test_changing_a_document_to_an_unknown_source_is_rejected(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $document = $this->createDocument($owner, $patient, ['source' => 'manual_upload']);

        $this->expectException(InvalidPhrDocumentSource::class);

        $document->update(['source' => 'outlook_email_attachment']);
    }

    public function test_a_stored_legacy_source_does_not_block_an_unrelated_edit(): void
    {
        // A row written before the rule (or by a raw insert) must not turn a
        // metadata edit into a server error; the data migration repairs it.
        [$owner, $patient] = $this->ownerAndPatient();
        $id = DB::table('phr_documents')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            'source' => 'onedrive_manual_review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PhrDocument::query()->findOrFail($id)->update(['title' => 'Renamed']);

        $this->assertSame('Renamed', PhrDocument::query()->findOrFail($id)->title);
    }

    public function test_normalize_keeps_allowed_values_and_maps_every_alias_into_the_list(): void
    {
        foreach (PhrDocument::SOURCES as $source) {
            $this->assertSame($source, PhrDocumentSource::normalize($source));
        }
        foreach (PhrDocumentSource::LEGACY_ALIASES as $alias => $target) {
            $this->assertContains($target, PhrDocument::SOURCES, $alias);
            $this->assertSame($target, PhrDocumentSource::normalize($alias));
        }
        $this->assertSame('fhir_import', PhrDocumentSource::normalize(' FHIR '));
        $this->assertSame('agent_upload', PhrDocumentSource::normalize('Agent_Upload'));
        $this->assertSame('manual_upload', PhrDocumentSource::normalize('something_else'));
        $this->assertSame('manual_upload', PhrDocumentSource::normalize(null));
    }

    public function test_manual_upload_stores_manual_upload(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        [$owner, $patient] = $this->ownerAndPatient();

        $this->actingAs($owner)->post("/api/phr/patients/{$patient->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', '%PDF-1.4 synthetic'),
            'document_type' => 'other',
        ])->assertCreated()->assertJsonPath('document.source', 'manual_upload');

        $this->assertSoleSource($patient, 'manual_upload');
    }

    public function test_agent_upload_stores_agent_upload(): void
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
            'external_id' => 'synthetic-source-001',
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', '%PDF-1.4 synthetic agent'),
            'document_type' => 'other',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.source', 'agent_upload');

        $this->assertSoleSource($patient, 'agent_upload');
    }

    public function test_genai_import_of_an_uploaded_file_stores_genai_import(): void
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

        app(PhrStructuredDataImporter::class)->storeGenAiDocument($patient, $owner->id, $job, ['title' => 'Synthetic']);

        $this->assertSoleSource($patient, 'genai_import');
    }

    /** @return iterable<string, array{array{import_source: string, source?: string}, string}> */
    public static function structuredDocumentOptions(): iterable
    {
        yield 'genai review' => [['import_source' => 'genai', 'source' => 'genai_import'], 'genai_import'];
        yield 'fhir alias' => [['import_source' => 'fhir'], 'fhir_import'];
        yield 'ccda alias' => [['import_source' => 'ccda'], 'ccda_import'];
        yield 'mychart alias' => [['import_source' => 'mychart'], 'mychart_zip'];
        yield 'retired cli alias' => [['import_source' => 'cli_pdf', 'source' => 'cli_pdf'], 'manual_upload'];
        yield 'agent upload is kept' => [['import_source' => 'synthetic', 'source' => 'agent_upload'], 'agent_upload'];
        yield 'ehi tables is kept' => [['import_source' => 'synthetic', 'source' => 'ehi_tables'], 'ehi_tables'];
    }

    /** @param array{import_source: string, source?: string} $options */
    #[DataProvider('structuredDocumentOptions')]
    public function test_structured_document_import_stores_an_allowed_source(array $options, string $expected): void
    {
        [$owner, $patient] = $this->ownerAndPatient();

        app(PhrStructuredDataImporter::class)->importPayload($patient, $owner->id, 'phr_document', [
            'external_id' => 'synthetic-structured-doc',
            'title' => 'Synthetic structured document',
        ], $options);

        $this->assertSoleSource($patient, $expected);
    }

    public function test_fhir_document_reference_stores_fhir_import(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $path = $this->tempFile(json_encode([
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'entry' => [[
                'resource' => [
                    'resourceType' => 'DocumentReference',
                    'id' => 'doc-1',
                    'description' => 'Synthetic note',
                    'content' => [['attachment' => ['contentType' => 'text/plain', 'title' => 'Synthetic note']]],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('phr:import:fhir', ['--patient' => $patient->id, '--actor' => $owner->id, '--file' => $path])
            ->assertExitCode(0);

        $this->assertSoleSource($patient, 'fhir_import');
    }

    public function test_cli_pdf_extraction_stores_manual_upload(): void
    {
        Storage::fake(PhrDocument::STORAGE_DISK);
        [$owner, $patient] = $this->ownerAndPatient();
        $pdf = $this->tempFile('%PDF-1.4 synthetic cli');
        $json = $this->tempFile(json_encode(['summary' => 'Synthetic summary'], JSON_THROW_ON_ERROR));

        $this->artisan('phr:import:pdf', [
            '--patient' => $patient->id,
            '--actor' => $owner->id,
            '--file' => $pdf,
            '--type' => 'phr_document',
            '--json' => $json,
        ])->assertExitCode(0);

        $this->assertSoleSource($patient, 'manual_upload');
    }

    public function test_ehi_tables_apply_stores_ehi_tables(): void
    {
        $this->setUpEhiDocuments();
        $this->ehiDocOwnedNote('N900', '9900');
        $this->ehiDocRow('HNO_PLAIN_TEXT', ['NOTE_CSN_ID' => '1', 'LINE' => '1', 'NOTE_ID' => 'N900', 'NOTE_TEXT' => 'synthetic body']);

        $draft = $this->ehiDocDraftFor($this->ehiDocPropose()['drafts'], 'note_id', 'N900');
        $document = PhrDocument::query()->findOrFail($this->ehiDocApply($this->ehiDocPersist($draft))->recordId);

        $this->assertSame('ehi_tables', $document->source);
    }

    /** @return array{User, PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Source Patient',
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
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            ...$attributes,
        ]);
    }

    private function assertSoleSource(PhrPatient $patient, string $expected): void
    {
        $this->assertSame([$expected], PhrDocument::query()->where('patient_id', $patient->id)->pluck('source')->all());
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phr-source-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
