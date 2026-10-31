<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PhrDocumentSourceMigrationTest extends TestCase
{
    private const string MIGRATION = 'migrations/2026_10_31_000000_normalize_legacy_phr_document_sources.php';

    /** stored value => value after the migration */
    private const array EXPECTED = [
        'fhir' => 'fhir_import',
        'cli' => 'manual_upload',
        'cli_pdf' => 'manual_upload',
        'onedrive_manual_review' => 'manual_upload',
        'outlook_email_attachment' => 'manual_upload',
        'genai' => 'genai_import',
        'ccda' => 'ccda_import',
        'mychart' => 'mychart_zip',
        'some_other_label' => 'manual_upload',
        '' => 'manual_upload',
        'Manual_Upload' => 'manual_upload',
        'manual_upload' => 'manual_upload',
        'agent_upload' => 'agent_upload',
        'genai_import' => 'genai_import',
        'fhir_import' => 'fhir_import',
        'ccda_import' => 'ccda_import',
        'mychart_zip' => 'mychart_zip',
        'ehi_tables' => 'ehi_tables',
    ];

    public function test_every_stored_source_is_mapped_into_the_allowed_list_without_touching_timestamps(): void
    {
        $patient = $this->patient();
        $stamp = '2026-08-08 09:00:00';
        $ids = [];
        foreach (array_keys(self::EXPECTED) as $source) {
            $ids[(string) $source] = $this->insertDocument($patient, (string) $source, $stamp);
        }
        $nullId = $this->insertDocument($patient, null, $stamp);
        $trashedId = $this->insertDocument($patient, 'outlook_email_attachment', $stamp, deleted: true);

        $this->migration()->up();

        foreach (self::EXPECTED as $stored => $expected) {
            $this->assertSame($expected, $this->sourceOf($ids[(string) $stored]), "stored '{$stored}'");
        }
        $this->assertSame('manual_upload', $this->sourceOf($nullId));
        // Withdrawn rows are still restorable and exported, so they are repaired too.
        $this->assertSame('manual_upload', $this->sourceOf($trashedId));
        $this->assertSame(
            [],
            DB::table('phr_documents')->where(fn ($query) => $query->whereNull('source')->orWhereNotIn('source', PhrDocument::SOURCES))->pluck('id')->all(),
        );
        // A relabel is not an edit: a native backup taken before the migration
        // must still recognise these rows, and its record hash covers updated_at.
        $this->assertSame([$stamp], DB::table('phr_documents')->distinct()->pluck('updated_at')->all());
    }

    public function test_it_is_idempotent_and_down_leaves_the_mapped_values(): void
    {
        $patient = $this->patient();
        $id = $this->insertDocument($patient, 'onedrive_manual_review', '2026-08-08 09:00:00');

        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame('manual_upload', $this->sourceOf($id));

        $this->migration()->down();
        $this->assertSame('manual_upload', $this->sourceOf($id));
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    private function patient(): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $this->createUser()->id,
            'display_name' => 'Synthetic Migration Patient',
            'relationship' => 'self',
        ]);
    }

    private function insertDocument(PhrPatient $patient, ?string $source, string $stamp, bool $deleted = false): int
    {
        // Raw inserts: these values predate the model's rule, which would refuse them.
        return DB::table('phr_documents')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'title' => 'Synthetic legacy document',
            'document_type' => 'other',
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'byte_size' => 0,
            'source' => $source,
            'created_at' => $stamp,
            'updated_at' => $stamp,
            'deleted_at' => $deleted ? $stamp : null,
        ]);
    }

    private function sourceOf(int $id): ?string
    {
        $source = DB::table('phr_documents')->where('id', $id)->value('source');

        return is_string($source) ? $source : null;
    }
}
