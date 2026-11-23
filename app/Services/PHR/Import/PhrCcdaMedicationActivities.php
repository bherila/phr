<?php

namespace App\Services\PHR\Import;

use App\Support\PHR\PhrMedicationCategory;
use DOMElement;

/**
 * Medication lists: one medication per Medication Activity.
 *
 * A medication list's activities are usually intents (moodCode INT), so every
 * mood is a medication. A negated activity ("no known medications") is a
 * negative assertion, not a medication; in a hospital list it says nothing
 * about the home list and is skipped.
 *
 * The section's route gives its list's setting (#413): the Medications section
 * is the home list (community), Hospital Discharge Medications the discharge
 * list. An activity inside a Discharge Medication act (.4.35) is the discharge
 * list's wherever the section that holds it was routed. One inside an Admission
 * Medication act (.4.36) is what the patient took before admission, a second
 * copy of the home list under other ids, and is skipped (#406). A row of any
 * list but the home list keeps its id scoped by the setting
 * (PhrMedicationCategory::scopedExternalId), so the same activity id in the home
 * and the discharge list is two rows, and never one row moved between them.
 */
final class PhrCcdaMedicationActivities extends PhrCcdaSectionReader
{
    private const array ACTIVITY = ['2.16.840.1.113883.10.20.22.4.16'];

    private const array INDICATION = ['2.16.840.1.113883.10.20.22.4.19'];

    /** Precondition for Substance Administration: the activity is taken as needed, for its criterion. */
    private const array PRECONDITION = ['2.16.840.1.113883.10.20.22.4.25'];

    /** Admission Medication: the act around the medications taken before admission, which are skipped. */
    private const string ADMISSION_WRAPPER = '2.16.840.1.113883.10.20.22.4.36';

    /** Discharge Medication: the act around a discharge list's activities. */
    private const string DISCHARGE_WRAPPER = '2.16.840.1.113883.10.20.22.4.35';

    private const string RXNORM = '2.16.840.1.113883.6.88';

    /** SNOMED "drug or medicament": a negation of any medication, not of one. */
    private const array ANY_MEDICATION = ['410942007'];

    public function sectionTemplates(): array
    {
        return ['2.16.840.1.113883.10.20.22.2.1', '2.16.840.1.113883.10.20.22.2.1.1'];
    }

    public function sectionCode(): string
    {
        return '10160-0';
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

    /** The section's entries, as the home list. */
    public function entries(DOMElement $section): array
    {
        return $this->read($section, PhrMedicationCategory::COMMUNITY);
    }

    /**
     * The section's entries, as the list of the setting its route names.
     *
     * @return list<PhrCcdaEntry>
     */
    public function entriesAs(DOMElement $section, string $category): array
    {
        return $this->read($section, $category);
    }

    /**
     * The entries this reader made of the section before #406: every activity,
     * hospital-list ones too, under its unscoped id and with no setting.
     * PhrCcdaMisroutedMedications recomputes from them the ids of the rows that
     * misrouting made.
     *
     * @return list<PhrCcdaEntry>
     */
    public function formerEntries(DOMElement $section): array
    {
        return $this->read($section, null);
    }

    /**
     * @param  string|null  $listCategory  the section's setting; null reads it as before #406
     * @return list<PhrCcdaEntry>
     */
    private function read(DOMElement $section, ?string $listCategory): array
    {
        $entries = [];
        foreach ($this->xml->entryElements($section, 'substanceAdministration', self::ACTIVITY) as $activity) {
            if ($listCategory !== null && $this->inWrapper($activity, self::ADMISSION_WRAPPER)) {
                $entries[] = $this->skipped();

                continue;
            }
            $category = $listCategory === null ? null : ($this->inWrapper($activity, self::DISCHARGE_WRAPPER) ? PhrMedicationCategory::DISCHARGE : $listCategory);

            $material = $this->xml->path($activity, 'consumable', 'manufacturedProduct', 'manufacturedMaterial');
            $code = $this->xml->child($material, 'code');
            $name = in_array($this->xml->attribute($code, 'code'), self::ANY_MEDICATION, true) ? null : $this->name($material);
            [$startedOn, $endedOn] = $this->period($activity);

            if ($this->negated($activity)) {
                if ($category !== null && $category !== PhrMedicationCategory::COMMUNITY) {
                    $entries[] = $this->skipped();

                    continue;
                }
                $statement = $this->xml->edText($this->xml->child($activity, 'text')) ?? ($name === null ? 'No known medications' : "Not taking {$name}");
                $entries[] = $this->negation($activity, 'medication_absence', 'medications', $statement, $startedOn);

                continue;
            }

            $record = $name === null ? null : $this->record($activity, $name, $code, $startedOn, $endedOn);
            $entries[] = $this->inList($this->entry($activity, $record, $this->references($activity, $this->xml->child($code, 'originalText'))), $category);
        }

        return $entries;
    }

    /** Whether an activity sits inside an act of this template. */
    private function inWrapper(DOMElement $activity, string $root): bool
    {
        return $this->xml->first('ancestor::*[*[local-name()="templateId"][@root="'.$root.'"]]', $activity) !== null;
    }

    /**
     * An entry as a row of the list `$category` names. A home-list row keeps every id
     * an earlier import may have stored it under. Any other list's row is scoped: its
     * record and table-row ids carry the setting, and the ids an earlier load gave a
     * home medication are not its own, so no home row is ever adopted onto it.
     *
     * An activity nothing names is read from its table row as a row of the same
     * list, under the same scoped id (#432): the section's own reading of its rows
     * would put a Discharge Medication act's row on the home list.
     */
    private function inList(PhrCcdaEntry $entry, ?string $category): PhrCcdaEntry
    {
        if ($category === null) {
            return $entry;
        }
        $record = $entry->record === null ? null : [
            ...$entry->record,
            'category' => $category,
            'external_id' => PhrMedicationCategory::scopedExternalId(is_string($entry->record['external_id'] ?? null) ? $entry->record['external_id'] : null, $category),
        ];
        $narrative = $record === null && $entry->narrativeCells !== null ? $this->narrativeRecordAs($entry->narrativeCells, $category) : null;
        if ($category === PhrMedicationCategory::COMMUNITY) {
            return new PhrCcdaEntry($entry->jobType, $record, $entry->narrativeCells, $entry->narrativeId, $entry->sourceIds, $entry->skip, $narrative);
        }

        return new PhrCcdaEntry($entry->jobType, $record, $entry->narrativeCells, PhrMedicationCategory::scopedExternalId($entry->narrativeId, $category), [], $entry->skip, $narrative);
    }

    public function narrativeRecord(array $cells): array
    {
        return $this->narrativeRecordAs($cells, PhrMedicationCategory::COMMUNITY);
    }

    /**
     * A table row of a list of the given setting, by position.
     *
     * @param  list<string>  $cells
     * @return array<string, string|null>
     */
    public function narrativeRecordAs(array $cells, string $category): array
    {
        return [
            'name' => $cells[0] ?? null,
            'dose' => $cells[1] ?? null,
            'frequency' => $cells[2] ?? null,
            'status' => $cells[3] ?? 'active',
            'category' => $category,
            'external_id' => PhrMedicationCategory::scopedExternalId($this->narrativeId($cells), $category),
        ];
    }

    /**
     * @return array<string, string|bool|null>
     */
    private function record(DOMElement $activity, string $name, ?DOMElement $code, ?string $startedOn, ?string $endedOn): array
    {
        [$doseValue, $doseUnit] = $this->dose($this->xml->child($activity, 'doseQuantity'));
        // UCUM's unity ("1") counts things; the administration unit says which.
        if ($doseUnit === null || $doseUnit === '1') {
            $doseUnit = $this->xml->codeName($this->xml->child($activity, 'administrationUnitCode')) ?? $doseUnit;
        }
        $route = $this->xml->child($activity, 'routeCode');
        $routeText = $this->xml->codeName($route) ?? $this->xml->codeLabel($route);
        $frequency = $this->frequency($activity);
        $indication = $this->xml->related($activity, 'observation', self::INDICATION)[0] ?? null;
        $status = $this->status($this->xml->attribute($this->xml->child($activity, 'statusCode'), 'code'));
        [$asNeeded, $asNeededReason] = $this->asNeeded($activity);

        return [
            'name' => $name,
            'rxnorm_code' => $this->xml->codeIn($code, self::RXNORM),
            'dose' => $doseValue,
            'dose_unit' => $doseValue === null ? null : $doseUnit,
            'route' => $routeText,
            'frequency' => $frequency,
            'started_on' => $startedOn,
            'ended_on' => $endedOn,
            'status' => $status,
            'reason_for_use' => $this->xml->codeName($this->xml->child($indication, 'value')),
            // The sig, as the source wrote it.
            'raw_text' => $this->xml->edText($this->xml->child($activity, 'text')),
            'as_needed' => $asNeeded,
            'as_needed_reason' => $asNeededReason,
            'rate' => $this->rate($this->xml->child($activity, 'rateQuantity')),
            'started_at' => $this->xml->hasTime($startedOn) ? $startedOn : null,
            'ended_at' => $this->xml->hasTime($endedOn) ? $endedOn : null,
            // Without an id, the whole regimen identifies it: the same medicine
            // twice a day and once a day are two activities, not one.
            'external_id' => $this->externalId($activity, [$name, $startedOn, $endedOn, $doseValue, $doseUnit, $routeText, $frequency]),
        ];
    }

    /**
     * Whether the activity is taken as needed, and for what: a Precondition for
     * Substance Administration says so, and its criterion's value (or text) is the
     * reason. With no precondition the source did not say, which is not "scheduled".
     *
     * @return array{0: bool|null, 1: string|null}
     */
    private function asNeeded(DOMElement $activity): array
    {
        foreach ($this->xml->children($activity, 'precondition') as $precondition) {
            $criterion = $this->xml->child($precondition, 'criterion');
            if (! $this->xml->hasTemplate($precondition, self::PRECONDITION) && $criterion === null) {
                continue;
            }
            $value = $this->xml->child($criterion, 'value');

            return [true, $this->xml->codeName($value) ?? $this->xml->edText($this->xml->child($criterion, 'text')) ?? $this->xml->edText($value)];
        }

        return [null, null];
    }

    /** A continuous infusion's rate, "75 mL/h", from its rateQuantity (a range keeps both bounds). */
    private function rate(?DOMElement $rate): ?string
    {
        [$value, $unit] = $this->dose($rate);

        return $value === null ? null : trim($value.' '.($unit ?? ''));
    }

    /**
     * A dose and its unit. A range keeps both bounds ("1-2"), and a single
     * bound says which it is (">=1", "<=2"), so it never reads as an exact dose.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function dose(?DOMElement $dose): array
    {
        $value = $this->xml->attribute($dose, 'value');
        if ($value !== null) {
            return [$value, $this->xml->attribute($dose, 'unit')];
        }

        $low = $this->xml->child($dose, 'low');
        $high = $this->xml->child($dose, 'high');
        $lowValue = $this->xml->attribute($low, 'value');
        $highValue = $this->xml->attribute($high, 'value');
        $text = match (true) {
            $lowValue !== null && $highValue !== null => "{$lowValue}-{$highValue}",
            $lowValue !== null => ($this->xml->attribute($low, 'inclusive') === 'false' ? '>' : '>=').$lowValue,
            $highValue !== null => ($this->xml->attribute($high, 'inclusive') === 'false' ? '<' : '<=').$highValue,
            default => null,
        };

        return [$text, $this->xml->attribute($low, 'unit') ?? $this->xml->attribute($high, 'unit')];
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
