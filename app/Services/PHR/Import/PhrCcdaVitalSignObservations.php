<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Vital Signs section: one vital per Vital Sign Observation.
 */
final class PhrCcdaVitalSignObservations extends PhrCcdaSectionReader
{
    private const array OBSERVATION = ['2.16.840.1.113883.10.20.22.4.27'];

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.4', '2.16.840.1.113883.10.20.22.2.4.1'];
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'vital');
    }

    public function jobType(): string
    {
        return 'phr_vital';
    }

    public function idPrefix(): string
    {
        return 'ccda-vital-';
    }

    public function legacyKind(): string
    {
        return 'vital';
    }

    public function entries(DOMElement $section): array
    {
        return array_map(
            fn (DOMElement $observation): PhrCcdaEntry => $this->entry($observation, $this->record($observation), $this->references($observation)),
            $this->xml->entryElements($section, 'observation', self::OBSERVATION),
        );
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'date' => $cells[0] ?? null,
            'name' => $cells[1] ?? null,
            'value' => $cells[2] ?? null,
            'unit' => $cells[3] ?? null,
            'external_id' => $this->narrativeId($cells),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function record(DOMElement $observation): ?array
    {
        $code = $this->xml->child($observation, 'code');
        $name = $this->xml->codeName($code)
            ?? $this->xml->edText($this->xml->child($observation, 'text'))
            ?? $this->xml->codeLabel($code);
        if ($name === null) {
            return null;
        }

        [$value, $unit] = $this->xml->value($this->xml->child($observation, 'value'));
        $organizer = $this->xml->first('ancestor::*[local-name()="organizer"][1]', $observation);
        $observedAt = $this->xml->period($this->xml->child($observation, 'effectiveTime'))[0]
            ?? $this->xml->period($this->xml->child($organizer, 'effectiveTime'))[0];

        return [
            'vital_name' => $name,
            'observed_at' => $this->xml->hasTime($observedAt) ? $observedAt : null,
            'vital_date' => $observedAt,
            'value' => $value,
            'unit' => $unit,
            'body_site' => $this->xml->codeName($this->xml->child($observation, 'targetSiteCode')),
            'external_id' => $this->externalId($observation, [$name, $observedAt, $value, $unit]),
        ];
    }
}
