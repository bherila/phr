<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Immunizations section: one immunization per Immunization Activity.
 *
 * Only doses given are records. A planned one (moodCode INT) is skipped; a
 * negated one (not given, for example refused) is a negative assertion.
 */
final class PhrCcdaImmunizationActivities extends PhrCcdaSectionReader
{
    private const array ACTIVITY = ['2.16.840.1.113883.10.20.22.4.52'];

    private const string CVX = '2.16.840.1.113883.12.292';

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.2', '2.16.840.1.113883.10.20.22.2.2.1'];
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'immunization');
    }

    public function jobType(): string
    {
        return 'phr_immunization';
    }

    public function idPrefix(): string
    {
        return 'ccda-immunization-';
    }

    public function legacyKind(): string
    {
        return 'immunization';
    }

    public function entries(DOMElement $section): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, 'substanceAdministration', self::ACTIVITY) as $activity) {
            if (! in_array($this->xml->attribute($activity, 'moodCode'), [null, 'EVN'], true)) {
                $entries[] = $this->skipped();

                continue;
            }

            $product = $this->xml->path($activity, 'consumable', 'manufacturedProduct');
            $material = $this->xml->child($product, 'manufacturedMaterial');
            $code = $this->xml->child($material, 'code');
            $name = $this->xml->codeName($code)
                ?? $this->xml->edText($this->xml->child($material, 'name'))
                ?? $this->xml->codeLabel($code);
            [$administeredOn] = $this->xml->period($this->xml->child($activity, 'effectiveTime'));

            if ($this->negated($activity)) {
                $statement = $this->xml->edText($this->xml->child($activity, 'text')) ?? ($name === null ? 'Immunization not given' : "{$name} not given");
                $entries[] = $this->negation($activity, 'immunization_exception', 'immunizations', $statement, $administeredOn);

                continue;
            }

            $performer = $this->xml->path($activity, 'performer', 'assignedEntity');
            // The administered amount (a PQ), kept as the exact text the source wrote.
            $dose = $this->xml->child($activity, 'doseQuantity');
            $doseAmount = $this->xml->attribute($dose, 'value');
            $doseUnit = $this->xml->attribute($dose, 'unit');
            $record = $name === null ? null : [
                'vaccine_name' => $name,
                'cvx_code' => $this->xml->codeIn($code, self::CVX),
                'manufacturer' => $this->xml->nameText($this->xml->path($product, 'manufacturerOrganization', 'name')),
                'lot_number' => $this->xml->edText($this->xml->child($material, 'lotNumberText')),
                'administered_on' => $administeredOn,
                'dose_number' => $this->xml->attribute($this->xml->child($activity, 'repeatNumber'), 'value'),
                'dose_amount' => $doseAmount,
                'dose_unit' => $doseAmount === null ? null : $doseUnit,
                'route' => $this->xml->codeName($this->xml->child($activity, 'routeCode')),
                'site' => $this->xml->codeName($this->xml->child($activity, 'approachSiteCode')),
                'administered_by' => $this->xml->nameText($this->xml->path($performer, 'assignedPerson', 'name')),
                'facility_name' => $this->xml->nameText($this->xml->path($performer, 'representedOrganization', 'name')),
                'external_id' => $this->externalId($activity, [$name, $administeredOn]),
            ];
            $entries[] = $this->entry($activity, $record, $this->references($activity, $this->xml->child($code, 'originalText')));
        }

        return $entries;
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'vaccine_name' => $cells[0] ?? null,
            'administered_on' => $cells[1] ?? null,
            'lot_number' => $cells[2] ?? null,
            'external_id' => $this->narrativeId($cells),
        ];
    }
}
