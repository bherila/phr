<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Support\PHR\PhrReviewStatus;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A document imported before results were read from their structured entries
 * holds lab rows under other external ids: the narrative path's hash of its
 * table row, or an earlier load's `<label>:lab:<root>|<extension>`. Re-importing
 * it must update those rows in place, not add a second set beside them.
 */
final class PhrCcdaLegacyLabAdoptionTest extends TestCase
{
    private const string FIXTURE = 'Fixtures/ccda/lab-reference-ranges.xml';

    private const int OBSERVATIONS = 9;

    public function test_reimporting_a_document_first_imported_from_its_narrative_updates_the_same_rows(): void
    {
        $patient = $this->patient();
        $importer = app(PhrCcdaImporter::class);

        // The narrative path, unchanged, is what imported the document before.
        $legacy = $importer->importXml($this->withoutEntries($this->fixture()), $patient, $patient->owner_user_id);
        $this->assertSame(self::OBSERVATIONS, $legacy->created);
        $ids = $this->labIds($patient);
        $screen = $this->narrativeScreenRow($patient);
        // The narrative path read the analysis time as the range.
        $this->assertSame('01/02/2026 3:04 AM PST', $screen->reference_range_text);
        $screen->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();

        $result = $importer->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $this->assertSame(['created' => 0, 'updated' => self::OBSERVATIONS], ['created' => $result->created, 'updated' => $result->updated]);
        $this->assertSame($ids, $this->labIds($patient), 'the same rows, no second set');
        $screen->refresh();
        $this->assertSame(['Synthetic screen', 'NEG', 'NEG'], [$screen->analyte, $screen->value, $screen->reference_range_text]);
        $this->assertSame('ccda-lab-'.sha1('id|2.25.2001|lab-st-neg'), $screen->external_id);
        // The source changed what a person had confirmed, so it waits for review
        // again, as for any changed row.
        $this->assertSame(PhrReviewStatus::PENDING, $screen->review_status);
    }

    public function test_reimporting_after_adoption_changes_nothing(): void
    {
        $patient = $this->patient();
        $importer = app(PhrCcdaImporter::class);
        $importer->importXml($this->withoutEntries($this->fixture()), $patient, $patient->owner_user_id);
        $ids = $this->labIds($patient);
        $importer->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $again = $importer->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => self::OBSERVATIONS], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged]);
        $this->assertSame($ids, $this->labIds($patient), 'still the rows the narrative import made');
    }

    public function test_a_result_the_earlier_import_did_not_have_is_still_added(): void
    {
        $patient = $this->patient();
        $importer = app(PhrCcdaImporter::class);
        $importer->importXml($this->withoutEntries($this->fixture()), $patient, $patient->owner_user_id);
        $ids = $this->labIds($patient);

        $result = $importer->importXml($this->withExtraObservation($this->fixture()), $patient, $patient->owner_user_id);

        $this->assertSame(1, $result->created);
        $this->assertSame(self::OBSERVATIONS, $result->updated);
        $this->assertCount(self::OBSERVATIONS + 1, $this->labIds($patient));
        $this->assertSame($ids, array_values(array_intersect($this->labIds($patient), $ids)));
    }

    public function test_rows_an_earlier_load_keyed_by_the_observation_id_are_adopted_by_exact_id_only(): void
    {
        $patient = $this->patient();
        $adopted = $this->earlierLoadRow($patient, 'synthetic-load:lab:2.25.2001|lab-st-neg', 'NEGNEG');
        // External ids are case-sensitive in production; a collation must not
        // make a different id match.
        $other = $this->earlierLoadRow($patient, 'synthetic-load:lab:2.25.2001|LAB-ST-NEG', 'NEG');

        $result = app(PhrCcdaImporter::class)->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $this->assertSame(self::OBSERVATIONS - 1, $result->created);
        $row = PhrLabResult::query()->findOrFail($adopted);
        $this->assertSame(['ccda', 'ccda-lab-'.sha1('id|2.25.2001|lab-st-neg'), 'NEG'], [$row->import_source, $row->external_id, $row->reference_range_text]);
        $untouched = PhrLabResult::query()->findOrFail($other);
        $this->assertSame(['synthetic_load', 'synthetic-load:lab:2.25.2001|LAB-ST-NEG', PhrReviewStatus::CONFIRMED], [$untouched->import_source, $untouched->external_id, $untouched->review_status]);
    }

    public function test_an_observation_matching_two_earlier_rows_adopts_one_and_leaves_the_other_for_review(): void
    {
        $patient = $this->patient();
        $importer = app(PhrCcdaImporter::class);
        $importer->importXml($this->withoutEntries($this->fixture()), $patient, $patient->owner_user_id);
        $narrative = $this->narrativeScreenRow($patient);
        $earlier = $this->earlierLoadRow($patient, 'synthetic-load:lab:2.25.2001|lab-st-neg', 'NEGNEG');

        $result = $importer->importXml($this->fixture(), $patient, $patient->owner_user_id);

        $this->assertSame(0, $result->created);
        // The row this importer wrote is the one carried forward.
        $this->assertSame('ccda-lab-'.sha1('id|2.25.2001|lab-st-neg'), $narrative->refresh()->external_id);
        // The other is neither merged nor deleted: it keeps its identity and
        // content and waits for a person to decide.
        $duplicate = PhrLabResult::query()->findOrFail($earlier);
        $this->assertSame(['synthetic_load', 'synthetic-load:lab:2.25.2001|lab-st-neg', 'NEGNEG', PhrReviewStatus::PENDING], [
            $duplicate->import_source, $duplicate->external_id, $duplicate->reference_range_text, $duplicate->review_status,
        ]);
        $this->assertCount(self::OBSERVATIONS + 1, $this->labIds($patient));

        // A person keeps the duplicate; the next import leaves that decision alone.
        $duplicate->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $importer->importXml($this->fixture(), $patient, $patient->owner_user_id);
        $this->assertSame(PhrReviewStatus::CONFIRMED, $duplicate->refresh()->review_status);
    }

    /** The row the narrative path made of the screen's table row, by the id it gave it. */
    private function narrativeScreenRow(PhrPatient $patient): PhrLabResult
    {
        $cells = ['Synthetic screen', 'NEG', 'NEG', '', '01/02/2026 3:04 AM PST'];

        return PhrLabResult::query()->where('patient_id', $patient->id)
            ->where('import_source', 'ccda')
            ->where('external_id', 'ccda-lab-'.sha1(implode('|', $cells)))
            ->sole();
    }

    private function earlierLoadRow(PhrPatient $patient, string $externalId, string $range): int
    {
        return DB::table('phr_lab_results')->insertGetId([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'synthetic_load',
            'external_id' => $externalId,
            'test_name' => 'Synthetic screen',
            'analyte' => 'Synthetic screen',
            'value' => 'NEG',
            'reference_range_text' => $range,
            'review_status' => PhrReviewStatus::CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function labIds(PhrPatient $patient): array
    {
        return PhrLabResult::query()->where('patient_id', $patient->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function fixture(): string
    {
        $xml = file_get_contents(base_path('tests/'.self::FIXTURE));
        $this->assertIsString($xml);

        return $xml;
    }

    /** The same document as the narrative path saw it: no structured entries. */
    private function withoutEntries(string $xml): string
    {
        [$document, $xpath] = $this->dom($xml);
        foreach (iterator_to_array($xpath->query('//*[local-name()="entry"]') ?: []) as $entry) {
            $entry->parentNode?->removeChild($entry);
        }

        return (string) $document->saveXML();
    }

    /** A later version of the document with one result the first did not have. */
    private function withExtraObservation(string $xml): string
    {
        [$document, $xpath] = $this->dom($xml);
        $component = $xpath->query('//*[local-name()="organizer"]/*[local-name()="component"]')?->item(0);
        $this->assertInstanceOf(DOMElement::class, $component);
        $copy = $component->cloneNode(true);
        $this->assertInstanceOf(DOMElement::class, $copy);
        $copyXpath = new DOMXPath($document);
        foreach (iterator_to_array($copyXpath->query('.//*[local-name()="id"]', $copy) ?: []) as $id) {
            $id instanceof DOMElement && $id->setAttribute('extension', 'lab-new');
        }
        foreach (iterator_to_array($copyXpath->query('.//*[local-name()="code"]', $copy) ?: []) as $code) {
            $code instanceof DOMElement && $code->setAttribute('displayName', 'Synthetic new screen');
        }
        foreach (iterator_to_array($copyXpath->query('./*/*[local-name()="text"]', $copy) ?: []) as $text) {
            $text->parentNode?->removeChild($text);
        }
        $component->parentNode?->appendChild($copy);

        return (string) $document->saveXML();
    }

    /** @return array{0: DOMDocument, 1: DOMXPath} */
    private function dom(string $xml): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));

        return [$document, new DOMXPath($document)];
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
