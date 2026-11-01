<?php

namespace Tests\Feature\PHR;

use App\Models\PhrAllergy;
use App\Models\PhrCondition;
use App\Models\PhrDocument;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use App\Models\PhrProcedure;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Services\PHR\Import\PhrMyChartZipImporter;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;
use ZipArchive;

/**
 * Rows a C-CDA, FHIR or MyChart import writes from a stored document link back
 * to it (`source_document_id`), and a row keeps the document it was first
 * linked to: a later import fills a missing link but never moves or clears one.
 */
final class PhrImportSourceDocumentLinkTest extends TestCase
{
    private const string LAB_FIXTURE = 'Fixtures/ccda/lab-reference-ranges.xml';

    /** @var list<class-string<Model>> */
    private const array CCDA_MODELS = [
        PhrLabResult::class,
        PhrPatientVital::class,
        PhrCondition::class,
        PhrMedication::class,
        PhrAllergy::class,
        PhrProcedure::class,
        PhrImmunization::class,
        PhrOfficeVisit::class,
    ];

    public function test_every_row_a_ccda_document_creates_is_linked_to_it(): void
    {
        $patient = $this->patient();
        $document = $this->document($patient);

        $result = app(PhrCcdaImporter::class)->importXml(PhrCcdaSyntheticDocument::xml(), $patient, $patient->owner_user_id, $document);

        $this->assertGreaterThan(0, $result->created);
        $rows = $this->rows($patient, self::CCDA_MODELS);
        $this->assertCount($result->created, $rows);
        foreach ($rows as $row) {
            $this->assertSame($document->id, $row->source_document_id, $row::class.' is linked');
        }
        // Where a row has a source, it names how the document was read.
        $this->assertSame(['ccda_import'], PhrLabResult::query()->where('patient_id', $patient->id)->distinct()->pluck('source')->all());
        $this->assertSame(['ccda_import'], PhrPatientVital::query()->where('patient_id', $patient->id)->distinct()->pluck('source')->all());
    }

    public function test_a_ccda_imported_without_a_stored_document_writes_unlinked_rows(): void
    {
        $patient = $this->patient();

        app(PhrCcdaImporter::class)->importXml(PhrCcdaSyntheticDocument::xml(), $patient, $patient->owner_user_id);

        foreach ($this->rows($patient, self::CCDA_MODELS) as $row) {
            $this->assertNull($row->source_document_id);
        }
    }

    public function test_a_reimport_links_rows_it_leaves_unchanged_without_reopening_their_review(): void
    {
        $patient = $this->patient();
        $importer = app(PhrCcdaImporter::class);
        $first = $importer->importXml(PhrCcdaSyntheticDocument::xml(), $patient, $patient->owner_user_id);
        foreach ($this->rows($patient, self::CCDA_MODELS) as $row) {
            $row->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        }
        $document = $this->document($patient);

        $again = $importer->importXml(PhrCcdaSyntheticDocument::xml(), $patient, $patient->owner_user_id, $document);

        // A link is provenance, not a fact the source changed.
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => $first->created], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged]);
        foreach ($this->rows($patient, self::CCDA_MODELS) as $row) {
            $this->assertSame([$document->id, PhrReviewStatus::CONFIRMED], [$row->source_document_id, $row->review_status], $row::class);
        }
    }

    public function test_a_row_a_document_changes_is_linked_if_it_had_no_link_and_keeps_its_first_link_otherwise(): void
    {
        $patient = $this->patient();
        $importer = app(PhrCcdaImporter::class);
        $importer->importXml($this->labFixture(), $patient, $patient->owner_user_id);
        $first = $this->document($patient, 'first.xml');

        $updated = $importer->importXml($this->withChangedGlucose($this->labFixture(), '150'), $patient, $patient->owner_user_id, $first);

        $this->assertSame(1, $updated->updated);
        $glucose = $this->labByObservation($patient, 'lab-ivl-no-text');
        $this->assertSame(['150', $first->id, PhrReviewStatus::PENDING], [$glucose->value, $glucose->source_document_id, $glucose->review_status]);

        // A later document that changes the row again does not move its link,
        // and an import from no stored document does not clear it.
        $later = $this->document($patient, 'later.xml');
        $this->assertSame(1, $importer->importXml($this->withChangedGlucose($this->labFixture(), '160'), $patient, $patient->owner_user_id, $later)->updated);
        $this->assertSame(1, $importer->importXml($this->withChangedGlucose($this->labFixture(), '170'), $patient, $patient->owner_user_id)->updated);
        $glucose->refresh();
        $this->assertSame(['170', $first->id, 'ccda_import'], [$glucose->value, $glucose->source_document_id, $glucose->source]);
    }

    public function test_a_row_adopted_on_reimport_keeps_its_document_link_and_source_or_gains_them(): void
    {
        $patient = $this->patient();
        $earlierDocument = $this->document($patient, 'earlier.xml');
        $linked = $this->earlierLoadRow($patient, 'lab-st-neg', $earlierDocument->id, 'Synthetic lab');
        $unlinked = $this->earlierLoadRow($patient, 'lab-st-detected', null, null);
        $document = $this->document($patient);

        app(PhrCcdaImporter::class)->importXml($this->labFixture(), $patient, $patient->owner_user_id, $document);

        $adopted = PhrLabResult::query()->findOrFail($linked);
        $this->assertSame(
            ['ccda', 'ccda-lab-'.sha1('id|2.25.2001|lab-st-neg'), $earlierDocument->id, 'Synthetic lab'],
            [$adopted->import_source, $adopted->external_id, $adopted->source_document_id, $adopted->source],
        );
        $gained = PhrLabResult::query()->findOrFail($unlinked);
        $this->assertSame(
            ['ccda-lab-'.sha1('id|2.25.2001|lab-st-detected'), $document->id, 'ccda_import'],
            [$gained->external_id, $gained->source_document_id, $gained->source],
        );
    }

    public function test_rows_a_fhir_bundle_writes_are_linked_and_keep_their_link(): void
    {
        $patient = $this->patient();
        $document = $this->document($patient, 'bundle.json');
        $importer = app(PhrFhirImporter::class);

        $created = $importer->importBundle($this->fhirBundle('120'), $patient, $patient->owner_user_id, $document);

        $this->assertSame(3, $created->created);
        foreach ($this->rows($patient, [PhrLabResult::class, PhrPatientVital::class, PhrCondition::class]) as $row) {
            $this->assertSame($document->id, $row->source_document_id, $row::class);
        }
        $this->assertSame('fhir_import', PhrLabResult::query()->where('patient_id', $patient->id)->sole()->source);

        $this->assertSame(1, $importer->importBundle($this->fhirBundle('130'), $patient, $patient->owner_user_id, $this->document($patient, 'later.json'))->updated);
        $this->assertSame(1, $importer->importBundle($this->fhirBundle('140'), $patient, $patient->owner_user_id)->updated);
        $lab = PhrLabResult::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(['140 mg/dL', $document->id], [$lab->value, $lab->source_document_id]);
    }

    public function test_rows_a_mychart_zip_writes_from_each_member_are_linked_to_the_zip_document(): void
    {
        $patient = $this->patient();
        $document = $this->document($patient, 'records.zip');
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'phr-link-mychart-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::OVERWRITE));
        $zip->addFromString('Records/bundle.json', (string) json_encode($this->fhirBundle('120')));
        $zip->addFromString('Records/summary.xml', PhrCcdaSyntheticDocument::xml());
        $zip->close();

        try {
            $result = app(PhrMyChartZipImporter::class)->importFile($zipPath, $patient, $patient->owner_user_id, $document);
        } finally {
            @unlink($zipPath);
        }

        $this->assertSame([], $result->warnings);
        $rows = $this->rows($patient, self::CCDA_MODELS);
        $this->assertCount($result->created, $rows);
        $this->assertSame(['fhir', 'ccda'], array_values(array_unique(array_map(static fn (Model $row): string => (string) $row->getAttribute('import_source'), $rows))));
        foreach ($rows as $row) {
            $this->assertSame($document->id, $row->source_document_id, $row::class.' from '.$row->getAttribute('import_source'));
        }
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return list<Model>
     */
    private function rows(PhrPatient $patient, array $models): array
    {
        $rows = [];
        foreach ($models as $model) {
            foreach ($model::query()->where('patient_id', $patient->id)->orderBy('id')->get() as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function labByObservation(PhrPatient $patient, string $extension): PhrLabResult
    {
        return PhrLabResult::query()->where('patient_id', $patient->id)
            ->where('external_id', 'ccda-lab-'.sha1('id|2.25.2001|'.$extension))
            ->sole();
    }

    private function earlierLoadRow(PhrPatient $patient, string $extension, ?int $documentId, ?string $source): int
    {
        return DB::table('phr_lab_results')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'synthetic_load',
            'external_id' => 'synthetic-load:lab:2.25.2001|'.$extension,
            'source_document_id' => $documentId,
            'source' => $source,
            'test_name' => 'Synthetic screen',
            'analyte' => 'Synthetic screen',
            'value' => 'earlier',
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function fhirBundle(string $glucose): array
    {
        return ['resourceType' => 'Bundle', 'entry' => [
            ['resource' => ['resourceType' => 'Observation', 'id' => 'synthetic-glucose', 'code' => ['text' => 'Synthetic glucose'], 'valueQuantity' => ['value' => (float) $glucose, 'unit' => 'mg/dL']]],
            ['resource' => ['resourceType' => 'Observation', 'id' => 'synthetic-pulse', 'category' => [['coding' => [['code' => 'vital-signs']]]], 'code' => ['text' => 'Synthetic pulse'], 'valueQuantity' => ['value' => 60, 'unit' => '/min']]],
            ['resource' => ['resourceType' => 'Condition', 'id' => 'synthetic-condition', 'code' => ['text' => 'Synthetic condition']]],
        ]];
    }

    private function labFixture(): string
    {
        $xml = file_get_contents(base_path('tests/'.self::LAB_FIXTURE));
        $this->assertIsString($xml);

        return $xml;
    }

    private function withChangedGlucose(string $xml, string $value): string
    {
        $original = '<value xsi:type="PQ" value="144" unit="mg/dL"/>';
        $this->assertStringContainsString($original, $xml);

        return str_replace($original, '<value xsi:type="PQ" value="'.$value.'" unit="mg/dL"/>', $xml);
    }

    private function document(PhrPatient $patient, string $filename = 'summary.xml'): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'uploaded_by_user_id' => $patient->owner_user_id,
            'title' => 'Synthetic structured document',
            'document_type' => 'other',
            'original_filename' => $filename,
            'mime_type' => 'application/xml',
            'byte_size' => 1,
            'source' => 'agent_upload',
        ]);
    }

    private function patient(): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $this->createUser()->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);
    }
}
