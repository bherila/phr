<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PhrCcdaImporter
{
    private const string IMPORT_SOURCE = 'ccda';

    public function __construct(
        private PhrStructuredDataImporter $structuredImporter,
        private PhrCcdaLegacyLabRows $legacyLabRows,
        private PhrCcdaLegacyRows $legacyRows,
        private PhrRecordAttributeMapper $attributeMapper,
    ) {}

    public function importFile(string $path, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        if (! File::isReadable($path)) {
            throw new InvalidArgumentException("CCDA file is not readable: {$path}");
        }

        return $this->importXml(File::get($path), $patient, $actorUserId);
    }

    public function importXml(string $xml, PhrPatient $patient, int $actorUserId): PhrImportResult
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new InvalidArgumentException('CCDA import expects a valid XML document.');
        }

        $xpath = new DOMXPath($document);
        $readers = $this->readers(new PhrCcdaXml($xpath));
        $result = new PhrImportResult;

        foreach ($xpath->query('//*[local-name()="section"]') ?: [] as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }

            $title = strtolower($this->sectionTitle($xpath, $section));
            if ($this->isResultsSection($title)) {
                // Structured Result Observations, when present, are the record;
                // the narrative table is only their rendering.
                $observations = (new PhrCcdaResultObservations($xpath))->observations($section);
                $readable = array_values(array_filter($observations, static fn (array $observation): bool => $observation['record'] !== null));
                if ($readable !== []) {
                    // Never fewer results than the narrative table: an observation
                    // nothing names is read from its own table row instead.
                    $unnamedRows = array_values(array_filter(array_map(static fn (array $observation): ?array => $observation['record'] === null ? $observation['narrative_cells'] : null, $observations)));
                    $result->merge(DB::transaction(function () use ($patient, $actorUserId, $readable, $unnamedRows): PhrImportResult {
                        // Rows an earlier import made of these results under other
                        // ids are moved to theirs first, so they update in place.
                        $this->legacyLabRows->adopt($patient, self::IMPORT_SOURCE, $readable);

                        $imported = $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_lab_result', array_column($readable, 'record'), ['import_source' => self::IMPORT_SOURCE]);
                        if ($unnamedRows !== []) {
                            $imported->merge($this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_lab_result', array_map($this->narrativeLabRecord(...), $unnamedRows), ['import_source' => self::IMPORT_SOURCE]));
                        }

                        return $imported;
                    }));

                    continue;
                }
            }

            $reader = $this->isResultsSection($title) ? null : $this->readerFor($readers, $xpath, $section, $title);
            if ($reader !== null) {
                // A section's structured entries, when it has any, are the
                // record; its narrative table is only their rendering.
                $entries = $reader->entries($section);
                if ($entries !== []) {
                    $result->merge($this->importEntries($patient, $actorUserId, $reader, $entries));

                    continue;
                }
            }

            $rows = $this->tableRows($xpath, $section);
            if ($rows === []) {
                continue;
            }

            $result->merge($reader === null
                ? $this->importRows($patient, $actorUserId, $title, $rows)
                : $this->structuredImporter->importUnreviewed($patient, $actorUserId, $reader->jobType(), array_map($reader->narrativeRecord(...), $rows), $this->options()));
        }

        return $result;
    }

    /**
     * The section readers, in the order a section's title is matched.
     *
     * @return list<PhrCcdaSectionReader>
     */
    private function readers(PhrCcdaXml $xml): array
    {
        return [
            new PhrCcdaVitalSignObservations($xml),
            new PhrCcdaMedicationActivities($xml),
            new PhrCcdaProblemObservations($xml),
        ];
    }

    /**
     * The reader for a section: by its template, else by its title.
     *
     * @param  list<PhrCcdaSectionReader>  $readers
     */
    private function readerFor(array $readers, DOMXPath $xpath, DOMElement $section, string $title): ?PhrCcdaSectionReader
    {
        $templates = [];
        foreach ($xpath->query('./*[local-name()="templateId"]/@root', $section) ?: [] as $root) {
            $templates[] = trim((string) $root->nodeValue);
        }
        foreach ($readers as $reader) {
            if (array_intersect($reader->sectionTemplates(), $templates) !== []) {
                return $reader;
            }
        }
        // A section without a known template is matched by its title, in the
        // order the narrative-table reader has always used.
        foreach ($readers as $reader) {
            if ($reader->matchesTitle($title)) {
                return $reader;
            }
        }

        return null;
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
                match (true) {
                    $entry->skip => $skipped++,
                    $entry->record !== null => $records[$entry->jobType][] = $entry->record,
                    $entry->narrativeCells !== null => $unnamed[] = $reader->narrativeRecord($entry->narrativeCells),
                    default => $skipped++,
                };
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
     * @param  array<int, array<int, string>>  $rows
     */
    private function importRows(PhrPatient $patient, int $actorUserId, string $title, array $rows): PhrImportResult
    {
        if ($this->isResultsSection($title)) {
            return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_lab_result', array_map($this->narrativeLabRecord(...), $rows), ['import_source' => self::IMPORT_SOURCE]);
        }

        if (str_contains($title, 'procedure')) {
            return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_procedure', array_map(
                fn (array $row): array => [
                    'name' => $row[0] ?? null,
                    'performed_on' => $row[1] ?? null,
                    'status' => $row[2] ?? 'completed',
                    'external_id' => 'ccda-procedure-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => self::IMPORT_SOURCE]);
        }

        if (str_contains($title, 'immunization')) {
            return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_immunization', array_map(
                fn (array $row): array => [
                    'vaccine_name' => $row[0] ?? null,
                    'administered_on' => $row[1] ?? null,
                    'lot_number' => $row[2] ?? null,
                    'external_id' => 'ccda-immunization-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => self::IMPORT_SOURCE]);
        }

        if (str_contains($title, 'allerg')) {
            return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_allergy', array_map(
                fn (array $row): array => [
                    'substance' => $row[0] ?? null,
                    'reaction' => $row[1] ?? null,
                    'severity' => $row[2] ?? null,
                    'external_id' => 'ccda-allergy-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => self::IMPORT_SOURCE]);
        }

        if (str_contains($title, 'encounter') || str_contains($title, 'visit')) {
            return $this->structuredImporter->importUnreviewed($patient, $actorUserId, 'phr_office_visit', array_map(
                fn (array $row): array => [
                    'visit_date' => $row[0] ?? null,
                    'visit_type' => $row[1] ?? null,
                    'provider_name' => $row[2] ?? null,
                    'chief_complaint' => $row[3] ?? null,
                    'assessment' => $row[4] ?? null,
                    'external_id' => 'ccda-visit-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => self::IMPORT_SOURCE]);
        }

        return new PhrImportResult(skipped: count($rows));
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

    /** Vital signs are matched first, so a "vital signs results" title stays a vitals section. */
    private function isResultsSection(string $title): bool
    {
        return ! str_contains($title, 'vital') && (str_contains($title, 'result') || str_contains($title, 'lab'));
    }

    private function sectionTitle(DOMXPath $xpath, DOMElement $section): string
    {
        $titleNodes = $xpath->query('./*[local-name()="title"]', $section);
        $title = $titleNodes === false ? null : $titleNodes->item(0);

        return $title instanceof DOMNode ? trim($title->textContent) : '';
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function tableRows(DOMXPath $xpath, DOMElement $section): array
    {
        $rows = [];
        foreach ($xpath->query('.//*[local-name()="tbody"]/*[local-name()="tr"] | .//*[local-name()="table"]/*[local-name()="tr"]', $section) ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            $cells = PhrCcdaNarrativeRow::cells($xpath, $tr);
            if (PhrCcdaNarrativeRow::isData($cells)) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }
}
