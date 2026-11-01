<?php

namespace App\Services\PHR\Import;

use DOMElement;

/**
 * Medications section: one medication per Medication Activity.
 *
 * A medication list's activities are usually intents (moodCode INT), so every
 * mood is a medication. A negated activity ("no known medications") is a
 * negative assertion, not a medication.
 */
final class PhrCcdaMedicationActivities extends PhrCcdaSectionReader
{
    private const array ACTIVITY = ['2.16.840.1.113883.10.20.22.4.16'];

    private const array INDICATION = ['2.16.840.1.113883.10.20.22.4.19'];

    private const string RXNORM = '2.16.840.1.113883.6.88';

    /** SNOMED "drug or medicament": a negation of any medication, not of one. */
    private const array ANY_MEDICATION = ['410942007'];

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.1', '2.16.840.1.113883.10.20.22.2.1.1'];
    }

    public function matchesTitle(string $title): bool
    {
        return str_contains($title, 'medication');
    }

    public function jobType(): string
    {
        return 'phr_medication';
    }

    public function idPrefix(): string
    {
        return 'ccda-med-';
    }

    public function legacyKind(): string
    {
        return 'medication';
    }

    public function entries(DOMElement $section): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, 'substanceAdministration', self::ACTIVITY) as $activity) {
            $material = $this->xml->path($activity, 'consumable', 'manufacturedProduct', 'manufacturedMaterial');
            $code = $this->xml->child($material, 'code');
            $name = in_array($this->xml->attribute($code, 'code'), self::ANY_MEDICATION, true) ? null : $this->name($material);
            [$startedOn, $endedOn] = $this->period($activity);

            if ($this->negated($activity)) {
                $statement = $this->xml->edText($this->xml->child($activity, 'text')) ?? ($name === null ? 'No known medications' : "Not taking {$name}");
                $entries[] = $this->negation($activity, 'medication_absence', 'medications', $statement, $startedOn);

                continue;
            }

            $entries[] = $this->entry($activity, $name === null ? null : $this->record($activity, $name, $code, $startedOn, $endedOn), $this->references($activity, $this->xml->child($code, 'originalText')));
        }

        return $entries;
    }

    public function narrativeRecord(array $cells): array
    {
        return [
            'name' => $cells[0] ?? null,
            'dose' => $cells[1] ?? null,
            'frequency' => $cells[2] ?? null,
            'status' => $cells[3] ?? 'active',
            'external_id' => $this->narrativeId($cells),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function record(DOMElement $activity, string $name, ?DOMElement $code, ?string $startedOn, ?string $endedOn): array
    {
        $dose = $this->xml->child($activity, 'doseQuantity');
        $doseValue = $this->xml->attribute($dose, 'value') ?? $this->xml->attribute($this->xml->child($dose, 'low'), 'value');
        $doseUnit = $this->xml->attribute($dose, 'unit') ?? $this->xml->attribute($this->xml->child($dose, 'low'), 'unit');
        // UCUM's unity ("1") counts things; the administration unit says which.
        if ($doseUnit === null || $doseUnit === '1') {
            $doseUnit = $this->xml->codeName($this->xml->child($activity, 'administrationUnitCode')) ?? $doseUnit;
        }
        $route = $this->xml->child($activity, 'routeCode');
        $indication = $this->xml->related($activity, 'observation', self::INDICATION)[0] ?? null;
        $status = $this->status($this->xml->attribute($this->xml->child($activity, 'statusCode'), 'code'));

        return [
            'name' => $name,
            'rxnorm_code' => $this->xml->codeIn($code, self::RXNORM),
            'dose' => $doseValue,
            'dose_unit' => $doseValue === null ? null : $doseUnit,
            'route' => $this->xml->codeName($route) ?? $this->xml->codeLabel($route),
            'frequency' => $this->frequency($activity),
            'started_on' => $startedOn,
            'ended_on' => $endedOn,
            'status' => $status,
            'reason_for_use' => $this->xml->codeName($this->xml->child($indication, 'value')),
            // The sig, as the source wrote it.
            'raw_text' => $this->xml->edText($this->xml->child($activity, 'text')),
            'external_id' => $this->externalId($activity, [$name, $startedOn, $doseValue, $doseUnit]),
        ];
    }

    /**
     * The product's code name, else its name, else the code itself.
     */
    private function name(?DOMElement $material): ?string
    {
        $code = $this->xml->child($material, 'code');

        return $this->xml->codeName($code)
            ?? $this->xml->edText($this->xml->child($material, 'name'))
            ?? $this->xml->codeLabel($code);
    }

    /**
     * When the medication was started and stopped: the effectiveTime that is an
     * interval, not a frequency.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function period(DOMElement $activity): array
    {
        foreach ($this->xml->children($activity, 'effectiveTime') as $effectiveTime) {
            if (! in_array($this->xml->type($effectiveTime), ['PIVL_TS', 'EIVL_TS', 'SXPR_TS'], true)) {
                return $this->xml->period($effectiveTime);
            }
        }

        return [null, null];
    }

    /**
     * "every 12 h" from a periodic time, or the event an event-related time
     * names ("at bedtime").
     */
    private function frequency(DOMElement $activity): ?string
    {
        foreach ($this->xml->children($activity, 'effectiveTime') as $effectiveTime) {
            $type = $this->xml->type($effectiveTime);
            if ($type === 'PIVL_TS') {
                $period = $this->xml->child($effectiveTime, 'period');
                $value = $this->xml->attribute($period, 'value');
                if ($value !== null) {
                    return trim("every {$value} ".($this->xml->attribute($period, 'unit') ?? ''));
                }
            } elseif ($type === 'EIVL_TS') {
                $event = $this->xml->child($effectiveTime, 'event');
                $text = $this->xml->codeName($event) ?? $this->xml->attribute($event, 'code');
                if ($text !== null) {
                    return $text;
                }
            }
        }

        return null;
    }

    /** A Medication Activity's statusCode in the app's medication statuses. */
    private function status(?string $code): ?string
    {
        return match ($code) {
            'active', 'new' => 'active',
            'completed' => 'completed',
            'aborted', 'cancelled' => 'discontinued',
            'suspended', 'held' => 'on_hold',
            default => null,
        };
    }
}
