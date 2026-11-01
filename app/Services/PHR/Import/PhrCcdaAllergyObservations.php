<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Allergies section: one allergy per Allergy - Intolerance Observation, usually
 * inside an Allergy Concern Act. A negated observation ("no known allergies")
 * is a negative assertion, not an allergy.
 */
final class PhrCcdaAllergyObservations extends PhrCcdaSectionReader
{
    private const array OBSERVATION = ['2.16.840.1.113883.10.20.22.4.7'];

    private const array CONCERN = ['2.16.840.1.113883.10.20.22.4.30'];

    private const array STATUS = ['2.16.840.1.113883.10.20.22.4.28'];

    private const array REACTION = ['2.16.840.1.113883.10.20.22.4.9'];

    private const array SEVERITY = ['2.16.840.1.113883.10.20.22.4.8'];

    private const array CRITICALITY = ['2.16.840.1.113883.10.20.22.4.145'];

    private const string RXNORM = '2.16.840.1.113883.6.88';

    private const string SNOMED = '2.16.840.1.113883.6.96';

    /** The observation's value (SNOMED allergy type) => the app's category. */
    private const array CATEGORIES = [
        '416098002' => 'medication', // drug allergy
        '59037007' => 'medication', // drug intolerance
        '419511003' => 'medication', // propensity to adverse reactions to drug
        '414285001' => 'food', // food allergy
        '235719002' => 'food', // food intolerance
        '418471000' => 'food', // propensity to adverse reactions to food
        '426232007' => 'environment', // environmental allergy
    ];

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.6', '2.16.840.1.113883.10.20.22.2.6.1'];
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'allerg');
    }

    public function jobType(): string
    {
        return 'phr_allergy';
    }

    public function idPrefix(): string
    {
        return 'ccda-allergy-';
    }

    public function legacyKind(): string
    {
        return 'allergy';
    }

    public function entries(DOMElement $section): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, 'observation', self::OBSERVATION) as $observation) {
            $entity = $this->xml->path($this->consumable($observation), 'participantRole', 'playingEntity');
            $code = $this->xml->child($entity, 'code');
            $substance = $this->xml->codeName($code)
                ?? $this->xml->edText($this->xml->child($entity, 'name'))
                ?? $this->xml->codeLabel($code);
            [$onset, $abated] = $this->xml->period($this->xml->child($observation, 'effectiveTime'));

            if ($this->negated($observation)) {
                $statement = $this->xml->edText($this->xml->child($observation, 'text')) ?? ($substance === null ? 'No known allergies' : "No allergy to {$substance}");
                $entries[] = $this->negation($observation, 'allergy_absence', 'allergies', $statement, $onset);

                continue;
            }

            $reactions = $this->xml->related($observation, 'observation', self::REACTION);
            $severity = $this->xml->related($observation, 'observation', self::SEVERITY)[0]
                ?? ($reactions === [] ? null : ($this->xml->related($reactions[0], 'observation', self::SEVERITY)[0] ?? null));
            $reaction = implode(', ', array_values(array_unique(array_filter(array_map(
                fn (DOMElement $reaction): ?string => $this->xml->codeName($this->xml->child($reaction, 'value')),
                $reactions,
            )))));

            $record = $substance === null ? null : [
                'substance' => $substance,
                'rxnorm_code' => $this->xml->codeIn($code, self::RXNORM),
                'snomed_code' => $this->xml->codeIn($code, self::SNOMED),
                'category' => self::CATEGORIES[$this->xml->attribute($this->xml->child($observation, 'value'), 'code') ?? ''] ?? null,
                'criticality' => $this->criticality($this->xml->related($observation, 'observation', self::CRITICALITY)[0] ?? null),
                'clinical_status' => $this->clinicalStatus($observation, $abated, self::STATUS, self::CONCERN),
                'reaction' => $reaction === '' ? null : $reaction,
                'severity' => $this->severity($severity),
                'external_id' => $this->externalId($observation, [$substance, $onset]),
            ];
            $entries[] = $this->entry($observation, $record, $this->references($observation, $this->xml->child($code, 'originalText')));
        }

        return $entries;
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'substance' => $cells[0] ?? null,
            'reaction' => $cells[1] ?? null,
            'severity' => $cells[2] ?? null,
            'external_id' => $this->narrativeId($cells),
        ];
    }

    /** The participant the allergy is to (typeCode CSM, consumable). */
    private function consumable(DOMElement $observation): ?DOMElement
    {
        foreach ($this->xml->children($observation, 'participant') as $participant) {
            if ($this->xml->attribute($participant, 'typeCode') === 'CSM') {
                return $participant;
            }
        }

        return null;
    }

    private function criticality(?DOMElement $observation): ?string
    {
        return match ($this->xml->attribute($this->xml->child($observation, 'value'), 'code')) {
            'CRITH' => 'high',
            'CRITL' => 'low',
            'CRITU' => 'unable_to_assess',
            default => null,
        };
    }
}
