<?php

namespace App\Services\PHR\Import;

use App\Models\PhrDocument;
use App\Models\PhrPatient;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PhrCcdaImporter
{
    private const string IMPORT_SOURCE = 'ccda';

    /** The record source for rows read from a stored document, a PhrDocument::SOURCES value. */
    private const string RECORD_SOURCE = 'ccda_import';

    public function __construct(
        private PhrStructuredDataImporter $structuredImporter,
        private PhrCcdaLegacyLabRows $legacyLabRows,
        private PhrCcdaLegacyRows $legacyRows,
        private PhrRecordAttributeMapper $attributeMapper,
        private PhrCcdaMisroutedMedications $misroutedMedications,
    ) {}

    public function importFile(string $path, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        if (! File::isReadable($path)) {
            throw new InvalidArgumentException("CCDA file is not readable: {$path}");
        }

        return $this->importXml(File::get($path), $patient, $actorUserId);
    }

    /**
     * With `$document`, the stored document the XML was read from, every row
     * written is linked to it (see PhrStructuredDataImporter::fromDocument()).
     *
     * Medication rows an earlier import of the same document made of its
     * hospital medication lists are retracted afterwards
     * (PhrCcdaMisroutedMedications). A caller importing several C-CDA documents
     * from one stored document (a MyChart ZIP) passes `$retractMisrouted` false
     * and calls retractMisroutedMedications() once with all of them, so one
     * member's medication list keeps another member's hospital list from
     * retracting a row both name.
     */
    public function importXml(string $xml, PhrPatient $patient, int $actorUserId, ?PhrDocument $document = null, bool $retractMisrouted = true): PhrImportResult
    {
        $xpath = $this->load($xml);

        return $this->structuredImporter->fromDocument($document, self::RECORD_SOURCE, function () use ($xpath, $patient, $actorUserId, $document, $retractMisrouted): PhrImportResult {
            $result = $this->readXml($xpath, $patient, $actorUserId);
            if ($retractMisrouted) {
                $result->merge($this->retractMisrouted([$xpath], $patient, $document));
            }

            return $result;
        });
    }

    /**
     * Retracts the medication rows earlier imports of these documents, all read
     * from `$document` (or from no stored document), made of hospital medication
     * lists, and restores those a document's own medication list names.
     *
     * @param  list<string>  $xmls
     */
    public function retractMisroutedMedications(array $xmls, PhrPatient $patient, ?PhrDocument $document = null): PhrImportResult
    {
        return $this->retractMisrouted(array_map($this->load(...), $xmls), $patient, $document);
    }

    /**
     * @param  list<DOMXPath>  $documents
     */
    private function retractMisrouted(array $documents, PhrPatient $patient, ?PhrDocument $document): PhrImportResult
    {
        $former = [];
        $current = [];
        foreach ($documents as $xpath) {
            $ids = $this->misroutedMedications->ids($xpath);
            $former = [...$former, ...$ids['former']];
            $current = [...$current, ...$ids['current']];
        }

        // A row with no source document may be one this document's earlier,
        // unlinked import made; a row linked to another document is left to
        // phr:ccda:retract-hospital-medications, which reads that document too.
        // Before a row is retracted, the patient's other stored documents are
        // read: one whose medication list names it keeps it.
        $outcome = $this->misroutedMedications->apply(
            (int) $patient->id,
            array_values(array_diff($former, $current)),
            array_values(array_unique($current)),
            $document === null ? [null] : [(int) $document->id, null],
            corroborated: fn (): array => $this->misroutedMedications->storedIds((int) $patient->id, $document === null ? null : (int) $document->id)['current'],
        );

        return new PhrImportResult(warnings: $outcome->warnings());
    }

    private function load(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new InvalidArgumentException('CCDA import expects a valid XML document.');
        }

        return new DOMXPath($document);
    }

    private function readXml(DOMXPath $xpath, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        $catalog = new PhrCcdaSectionCatalog(new PhrCcdaXml($xpath));
        $result = new PhrImportResult;

        foreach ($xpath->query('//*[local-name()="section"]') ?: [] as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }

            $route = $catalog->route($section);
            if ($route->unread !== null) {
                // A known section no reader reads (a hospital medication list, a
                // procedure's findings, a reason for visit) is never read as
                // another kind: its entries, or else its table rows, are skipped.
                $entries = $xpath->query('./*[local-name()="entry"]', $section);
                $result->addSkipped(($entries === false ? 0 : $entries->length) ?: count(PhrCcdaNarrativeRow::rows($xpath, $section)));

                continue;
            }

            if ($route->results) {
                $result->merge($this->importResults($xpath, $section, $patient, $actorUserId));

                continue;
            }

            $reader = $route->reader;
            if ($reader !== null) {
                // A section's structured entries, when it has any, are the
                // record; its narrative table is only their rendering.
                $entries = $reader->entries($section);
                if ($entries !== []) {
                    $result->merge($this->importEntries($patient, $actorUserId, $reader, $entries));

                    continue;
                }
            }

            $rows = PhrCcdaNarrativeRow::rows($xpath, $section);
            if ($rows === []) {
                continue;
            }

            $result->merge($reader === null
                ? new PhrImportResult(skipped: count($rows))
                : $this->structuredImporter->importUnreviewed($patient, $actorUserId, $reader->jobType(), array_map($reader->narrativeRecord(...), $rows), $this->options()));
        }

        return $result;
    }

    /**
     * A Results section. Structured Result Observations, when present, are the
     * record; the narrative table is only their rendering, read by position
     * when no observation is readable.
     */
    private function importResults(DOMXPath $xpath, DOMElement $section, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        $observations = (new PhrCcdaResultObservations($xpath))->observations($section);
        $readable = array_values(array_filter($observations, static fn (array $observation): bool => $observation['record'] !== null));
        if ($readable === []) {
            $rows = PhrCcdaNarrativeRow::rows($xpath, $section);

            return $rows === [] ? new PhrImportResult : $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_lab_result', array_map($this->narrativeLabRecord(...), $rows), $this->options());
        }

        // Never fewer results than the narrative table: an observation
        // nothing names is read from its own table row instead.
        $unnamedRows = array_values(array_filter(array_map(static fn (array $observation): ?array => $observation['record'] === null ? $observation['narrative_cells'] : null, $observations)));

        return DB::transaction(function () use ($patient, $actorUserId, $readable, $unnamedRows): PhrImportResult {
            // Rows an earlier import made of these results under other
            // ids are moved to theirs first, so they update in place.
            $this->legacyLabRows->adopt($patient, self::IMPORT_SOURCE, $readable);

            $imported = $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_lab_result', array_column($readable, 'record'), $this->options());
            if ($unnamedRows !== []) {
                $imported->merge($this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_lab_result', array_map($this->narrativeLabRecord(...), $unnamedRows), $this->options()));
            }

            return $imported;
        });
    }

    /**
     * A section's structured entries. Rows an earlier import made of them under
     * other ids are moved to theirs first, so they update in place; an entry
     * nothing names is read from its own table row instead.
     *
     * @param  list<PhrCcdaEntry>  $entries
     */
    private function importEntries(PhrPatient $patient, int $actorUserId, PhrCcdaSectionReader $reader, array $entries): PhrImportResult
    {
        return DB::transaction(function () use ($patient, $actorUserId, $reader, $entries): PhrImportResult {
            $own = array_values(array_filter($entries, static fn (PhrCcdaEntry $entry): bool => $entry->record !== null && $entry->jobType === $reader->jobType()));
            $this->legacyRows->adopt($this->attributeMapper->modelClassFor($reader->jobType()), (int) $patient->id, self::IMPORT_SOURCE, $reader->legacyKind(), $own);

            $records = [];
            $unnamed = [];
            $skipped = 0;
            foreach ($entries as $entry) {
                if ($entry->skip) {
                    $skipped++;
                } elseif ($entry->record !== null) {
                    $records[$entry->jobType][] = $entry->record;
                } elseif ($entry->narrativeCells !== null) {
                    $unnamed[] = $reader->narrativeRecord($entry->narrativeCells);
                } else {
                    $skipped++;
                }
            }

            $result = new PhrImportResult(skipped: $skipped);
            foreach ($records as $jobType => $typeRecords) {
                $result->merge($this->structuredImporter->importUnreviewed($patient, $actorUserId, $jobType, $typeRecords, $this->options()));
            }
            if ($unnamed !== []) {
                $result->merge($this->structuredImporter->importUnreviewed($patient, $actorUserId, $reader->jobType(), $unnamed, $this->options()));
            }

            return $result;
        });
    }

    /**
     * @return array{import_source: string}
     */
    private function options(): array
    {
        return ['import_source' => self::IMPORT_SOURCE];
    }

    /**
     * A lab row read from a narrative table row, by position.
     *
     * @param  array<int, string>  $row
     * @return array<string, string|null>
     */
    private function narrativeLabRecord(array $row): array
    {
        return [
            'observed_at' => $row[0] ?? null,
            'test_name' => $row[1] ?? null,
            'analyte' => $row[1] ?? null,
            'value' => $row[2] ?? null,
            'unit' => $row[3] ?? null,
            'reference_range_text' => $row[4] ?? null,
            'abnormal_flag' => $row[5] ?? null,
            'external_id' => PhrCcdaNarrativeRow::labExternalId($row),
        ];
    }
}
