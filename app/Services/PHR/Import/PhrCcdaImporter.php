<?php

namespace App\Services\PHR\Import;

use App\Models\PhrPatient;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PhrCcdaImporter
{
    public function __construct(private PhrStructuredDataImporter $structuredImporter) {}

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
        $result = new PhrImportResult;

        foreach ($xpath->query('//*[local-name()="section"]') ?: [] as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }

            $title = strtolower($this->sectionTitle($xpath, $section));
            $rows = $this->tableRows($xpath, $section);
            if ($rows === []) {
                continue;
            }

            $result->merge($this->importRows($patient, $actorUserId, $title, $rows));
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function importRows(PhrPatient $patient, int $actorUserId, string $title, array $rows): PhrImportResult
    {
        if (str_contains($title, 'vital')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_vital', array_map(
                fn (array $row): array => [
                    'date' => $row[0] ?? null,
                    'name' => $row[1] ?? null,
                    'value' => $row[2] ?? null,
                    'unit' => $row[3] ?? null,
                    'external_id' => 'ccda-vital-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        if (str_contains($title, 'result') || str_contains($title, 'lab')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_lab_result', array_map(
                fn (array $row): array => [
                    'observed_at' => $row[0] ?? null,
                    'test_name' => $row[1] ?? null,
                    'analyte' => $row[1] ?? null,
                    'value' => $row[2] ?? null,
                    'unit' => $row[3] ?? null,
                    'reference_range_text' => $row[4] ?? null,
                    'abnormal_flag' => $row[5] ?? null,
                    'external_id' => 'ccda-lab-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        if (str_contains($title, 'medication')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_medication', array_map(
                fn (array $row): array => [
                    'name' => $row[0] ?? null,
                    'dose' => $row[1] ?? null,
                    'frequency' => $row[2] ?? null,
                    'status' => $row[3] ?? 'active',
                    'external_id' => 'ccda-med-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        if (str_contains($title, 'problem') || str_contains($title, 'condition')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_problem_list', array_map(
                fn (array $row): array => [
                    'name' => $row[0] ?? null,
                    'icd10_code' => $row[1] ?? null,
                    'clinical_status' => $row[2] ?? 'active',
                    'onset_date' => $row[3] ?? null,
                    'external_id' => 'ccda-condition-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        if (str_contains($title, 'procedure')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_procedure', array_map(
                fn (array $row): array => [
                    'name' => $row[0] ?? null,
                    'performed_on' => $row[1] ?? null,
                    'status' => $row[2] ?? 'completed',
                    'external_id' => 'ccda-procedure-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        if (str_contains($title, 'immunization')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_immunization', array_map(
                fn (array $row): array => [
                    'vaccine_name' => $row[0] ?? null,
                    'administered_on' => $row[1] ?? null,
                    'lot_number' => $row[2] ?? null,
                    'external_id' => 'ccda-immunization-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        if (str_contains($title, 'allerg')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_allergy', array_map(
                fn (array $row): array => [
                    'substance' => $row[0] ?? null,
                    'reaction' => $row[1] ?? null,
                    'severity' => $row[2] ?? null,
                    'external_id' => 'ccda-allergy-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        if (str_contains($title, 'encounter') || str_contains($title, 'visit')) {
            return $this->structuredImporter->importPayload($patient, $actorUserId, 'phr_office_visit', array_map(
                fn (array $row): array => [
                    'visit_date' => $row[0] ?? null,
                    'visit_type' => $row[1] ?? null,
                    'provider_name' => $row[2] ?? null,
                    'chief_complaint' => $row[3] ?? null,
                    'assessment' => $row[4] ?? null,
                    'external_id' => 'ccda-visit-'.sha1(implode('|', $row)),
                ],
                $rows
            ), ['import_source' => 'ccda']);
        }

        return new PhrImportResult(skipped: count($rows));
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

            $cells = [];
            foreach ($xpath->query('./*[local-name()="td" or local-name()="th"]', $tr) ?: [] as $cell) {
                $cells[] = preg_replace('/\s+/', ' ', trim($cell->textContent)) ?: '';
            }

            if ($cells !== [] && $this->hasNonHeaderData($cells)) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * Best-effort header-row skip. CCDA narrative tables vary by vendor and have no
     * stable scope/role markers, so this matches a small set of common English labels;
     * localized exports or non-standard headers will fall through and import as data.
     *
     * @param  array<int, string>  $cells
     */
    private function hasNonHeaderData(array $cells): bool
    {
        $first = strtolower($cells[0] ?? '');

        return ! in_array($first, ['date', 'name', 'test', 'vaccine', 'medication', 'substance'], true);
    }
}
