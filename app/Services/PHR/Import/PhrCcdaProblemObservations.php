<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Problems section: one condition per Problem Observation, usually inside a
 * Problem Concern Act. A negated observation ("no known problems") is a
 * negative assertion, not a condition.
 */
final class PhrCcdaProblemObservations extends PhrCcdaSectionReader
{
    private const array OBSERVATION = ['2.16.840.1.113883.10.20.22.4.4'];

    private const array CONCERN = ['2.16.840.1.113883.10.20.22.4.3'];

    private const array STATUS = ['2.16.840.1.113883.10.20.22.4.6'];

    private const array SEVERITY = ['2.16.840.1.113883.10.20.22.4.8'];

    private const string ICD10_CM = '2.16.840.1.113883.6.90';

    private const string SNOMED = '2.16.840.1.113883.6.96';

    /** SNOMED "problem": a negation of any problem, not of one. */
    private const array ANY_PROBLEM = ['55607006'];

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.5', '2.16.840.1.113883.10.20.22.2.5.1'];
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'problem') || str_contains($title, 'condition');
    }

    public function jobType(): string
    {
        return 'phr_problem_list';
    }

    public function idPrefix(): string
    {
        return 'ccda-condition-';
    }

    public function legacyKind(): string
    {
        return 'condition';
    }

    public function entries(DOMElement $section): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, 'observation', self::OBSERVATION) as $observation) {
            $value = $this->xml->child($observation, 'value');
            $text = $this->xml->child($observation, 'text');
            $name = in_array($this->xml->attribute($value, 'code'), self::ANY_PROBLEM, true) ? null : (
                $this->xml->codeName($value) ?? $this->xml->edText($text) ?? $this->xml->codeLabel($value)
            );
            [$onset, $abated] = $this->xml->period($this->xml->child($observation, 'effectiveTime'));

            if ($this->negated($observation)) {
                $statement = $this->xml->edText($text) ?? ($name === null ? 'No known problems' : "No {$name}");
                $entries[] = $this->negation($observation, 'condition_absence', 'problems', $statement, $onset);

                continue;
            }

            $record = $name === null ? null : [
                'name' => $name,
                'icd10_code' => $this->xml->codeIn($value, self::ICD10_CM),
                'snomed_code' => $this->xml->codeIn($value, self::SNOMED),
                'onset_date' => $onset,
                'abated_date' => $abated,
                'clinical_status' => $this->clinicalStatus($observation, $abated),
                'severity' => $this->severity($this->xml->related($observation, 'observation', self::SEVERITY)[0] ?? null),
                'external_id' => $this->externalId($observation, [$name, $onset]),
            ];
            $entries[] = $this->entry($observation, $record, $this->references($observation, $this->xml->child($value, 'originalText')));
        }

        return $entries;
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'name' => $cells[0] ?? null,
            'icd10_code' => $cells[1] ?? null,
            'clinical_status' => $cells[2] ?? 'active',
            'onset_date' => $cells[3] ?? null,
            'external_id' => $this->narrativeId($cells),
        ];
    }

    /**
     * The problem's own status observation (SNOMED active, inactive, resolved)
     * when it has one; else resolved once it has abated; else its concern's
     * status: a completed concern is resolved, a suspended one inactive.
     */
    private function clinicalStatus(DOMElement $observation, ?string $abated): string
    {
        $status = $this->xml->attribute($this->xml->child($this->xml->related($observation, 'observation', self::STATUS)[0] ?? null, 'value'), 'code');
        $mapped = match ($status) {
            '55561003' => 'active',
            '73425007' => 'inactive',
            '413322009' => 'resolved',
            default => null,
        };
        if ($mapped !== null) {
            return $mapped;
        }
        if ($abated !== null) {
            return 'resolved';
        }

        $concern = $this->xml->first('ancestor::*[local-name()="act"][1]', $observation);
        $concern = $concern !== null && $this->xml->hasTemplate($concern, self::CONCERN) ? $concern : null;

        return match ($this->xml->attribute($this->xml->child($concern, 'statusCode'), 'code')) {
            'completed' => 'resolved',
            'suspended' => 'inactive',
            default => 'active',
        };
    }
}
