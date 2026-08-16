<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\PhrStructuredDataImporter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: parsed import payloads must not choose where a document lives.
 *
 * PhrDocumentImporter::createOrUpdateDocument read `storage_disk` and
 * `storage_path` straight out of the payload — untrusted parsed input from a
 * FHIR bundle or a model's JSON output. A crafted document could therefore
 * create a PhrDocument row pointing at an arbitrary object on an arbitrary
 * disk, and the document file/download endpoints would happily stream it.
 */
class PhrDocumentImportStoragePathTest extends TestCase
{
    public function test_import_payload_cannot_choose_the_storage_location(): void
    {
        Storage::fake('phr_documents');
        Storage::fake('local');
        Storage::disk('local')->put('secrets/other-patient.pdf', 'another patients bytes');

        [$patient, $actor] = $this->patientAndActor();

        $this->importer()->importPayload($patient, $actor->id, 'phr_document', [
            'title' => 'Crafted reference',
            'document_type' => 'lab_report',
            'external_id' => 'crafted-1',
            'storage_disk' => 'local',
            'storage_path' => 'secrets/other-patient.pdf',
        ], ['import_source' => 'fhir']);

        $document = PhrDocument::query()->where('external_id', 'crafted-1')->sole();

        $this->assertNull(
            $document->storage_path,
            'A metadata-only import must not adopt a payload-supplied path.',
        );
        $this->assertSame(PhrDocument::STORAGE_DISK, $document->storage_disk);

        // And the read path yields nothing rather than the foreign file.
        $this->actingAs($actor)
            ->get("/api/phr/patients/{$patient->id}/documents/{$document->id}/file")
            ->assertNotFound();
    }

    public function test_reimport_does_not_clear_the_path_of_an_already_stored_document(): void
    {
        Storage::fake('phr_documents');

        [$patient, $actor] = $this->patientAndActor();

        $sourcePath = tempnam(sys_get_temp_dir(), 'phr-doc-');
        file_put_contents($sourcePath, 'real document bytes');

        try {
            $stored = $this->importer()->storeLocalDocument($patient, $actor->id, $sourcePath, [
                'title' => 'Real document',
                'document_type' => 'lab_report',
                'original_filename' => 'real.pdf',
                'import_source' => 'fhir',
                'external_id' => 'stable-1',
            ]);
        } finally {
            @unlink($sourcePath);
        }

        $originalPath = $stored->storage_path;
        $this->assertNotNull($originalPath);
        $this->assertMatchesRegularExpression(
            '#^patients/'.$patient->id.'/documents/[0-9a-f-]{36}/real\.pdf$#',
            $originalPath,
        );

        // A second pass over the same source upserts by (import_source,
        // external_id). Omitting the storage columns must leave the stored
        // file's location intact rather than nulling it.
        $this->importer()->importPayload($patient, $actor->id, 'phr_document', [
            'title' => 'Real document, retitled',
            'document_type' => 'lab_report',
            'external_id' => 'stable-1',
            'storage_path' => 'secrets/somewhere-else.pdf',
        ], ['import_source' => 'fhir']);

        $reloaded = PhrDocument::query()->findOrFail($stored->id);
        $this->assertSame('Real document, retitled', $reloaded->title);
        $this->assertSame($originalPath, $reloaded->storage_path);
        $this->assertSame(PhrDocument::STORAGE_DISK, $reloaded->storage_disk);

        $response = $this->actingAs($actor)
            ->get("/api/phr/patients/{$patient->id}/documents/{$reloaded->id}/file")
            ->assertOk();
        $this->assertSame('real document bytes', $response->streamedContent());
    }

    public function test_stream_refuses_a_row_carrying_a_foreign_disk(): void
    {
        Storage::fake('phr_documents');
        Storage::fake('local');
        Storage::disk('local')->put('secrets/other-patient.pdf', 'another patients bytes');

        [$patient, $actor] = $this->patientAndActor();

        // Simulate a row that acquired a foreign disk by any route at all.
        $document = PhrDocument::create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'document_type' => 'other',
            'storage_disk' => 'local',
            'storage_path' => 'secrets/other-patient.pdf',
            'byte_size' => 22,
        ]);

        $this->actingAs($actor)
            ->get("/api/phr/patients/{$patient->id}/documents/{$document->id}/file")
            ->assertNotFound();
    }

    /** @return array{0: PhrPatient, 1: User} */
    private function patientAndActor(): array
    {
        $actor = $this->createUser();
        $patientId = (int) $this->actingAs($actor)
            ->postJson('/api/phr/patients', ['display_name' => 'Primary'])
            ->assertCreated()
            ->json('patient.id');

        return [PhrPatient::query()->findOrFail($patientId), $actor];
    }

    private function importer(): PhrStructuredDataImporter
    {
        return app(PhrStructuredDataImporter::class);
    }
}
