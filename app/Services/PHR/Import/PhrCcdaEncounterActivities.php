<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Encounters section: one visit per Encounter Activity. Only encounters that
 * happened are records; a planned one (any mood but EVN) or a negated one is
 * skipped.
 */
final class PhrCcdaEncounterActivities extends PhrCcdaSectionReader
{
    private const array ACTIVITY = ['2.16.840.1.113883.10.20.22.4.49'];

    private const array INDICATION = ['2.16.840.1.113883.10.20.22.4.19'];

    private const array DIAGNOSIS = ['2.16.840.1.113883.10.20.22.4.80'];

    private const array PROBLEM = ['2.16.840.1.113883.10.20.22.4.4'];

    private const string ICD10_CM = '2.16.840.1.113883.6.90';

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.22', '2.16.840.1.113883.10.20.22.2.22.1'];
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'encounter') || str_contains($title, 'visit');
    }

    public function jobType(): string
    {
        return 'phr_office_visit';
    }

    public function idPrefix(): string
    {
        return 'ccda-visit-';
    }

    public function legacyKind(): string
    {
        return 'encounter';
    }

    public function entries(DOMElement $section): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, 'encounter', self::ACTIVITY) as $encounter) {
            if ($this->negated($encounter) || ! in_array($this->xml->attribute($encounter, 'moodCode'), [null, 'EVN'], true)) {
                $entries[] = $this->skipped();

                continue;
            }

            $entries[] = $this->entry($encounter, $this->record($encounter), $this->references($encounter));
        }

        return $entries;
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'visit_date' => $cells[0] ?? null,
            'visit_type' => $cells[1] ?? null,
            'provider_name' => $cells[2] ?? null,
            'chief_complaint' => $cells[3] ?? null,
            'assessment' => $cells[4] ?? null,
            'external_id' => $this->narrativeId($cells),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(DOMElement $encounter): array
    {
        $code = $this->xml->child($encounter, 'code');
        $type = $this->xml->codeName($code) ?? $this->xml->codeLabel($code);
        [$started, $ended] = $this->xml->period($this->xml->child($encounter, 'effectiveTime'));
        $performer = $this->xml->path($encounter, 'performer', 'assignedEntity');
        $location = null;
        foreach ($this->xml->children($encounter, 'participant') as $participant) {
            if ($this->xml->attribute($participant, 'typeCode') === 'LOC') {
                $location = $this->xml->path($participant, 'participantRole', 'playingEntity');
                break;
            }
        }
        $indication = $this->xml->related($encounter, 'observation', self::INDICATION)[0] ?? null;

        return [
            'visit_date' => $started,
            'visit_started_at' => $this->xml->hasTime($started) ? $started : null,
            'visit_ended_at' => $this->xml->hasTime($ended) ? $ended : null,
            'visit_type' => $type,
            'provider_name' => $this->xml->nameText($this->xml->path($performer, 'assignedPerson', 'name')),
            'provider_specialty' => $this->xml->codeName($this->xml->child($performer, 'code')),
            'facility_name' => $this->xml->nameText($this->xml->child($location, 'name'))
                ?? $this->xml->nameText($this->xml->path($performer, 'representedOrganization', 'name')),
            'chief_complaint' => $this->xml->codeName($this->xml->child($indication, 'value')),
            'icd10_codes' => $this->diagnoses($encounter),
            'external_id' => $this->externalId($encounter, [$started, $type]),
        ];
    }

    /**
     * The ICD-10-CM codes of the encounter's diagnoses, each with its name.
     *
     * @return list<array{code: string, description: string}>|null
     */
    private function diagnoses(DOMElement $encounter): ?array
    {
        $codes = [];
        foreach ($this->xml->related($encounter, 'act', self::DIAGNOSIS) as $diagnosis) {
            foreach ($this->xml->related($diagnosis, 'observation', self::PROBLEM) as $problem) {
                $value = $this->xml->child($problem, 'value');
                $code = $this->xml->codeIn($value, self::ICD10_CM);
                if ($code !== null) {
                    $codes[] = ['code' => $code, 'description' => $this->xml->codeName($value) ?? ''];
                }
            }
        }

        return $codes === [] ? null : $codes;
    }
}
