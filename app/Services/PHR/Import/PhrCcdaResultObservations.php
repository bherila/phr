<?php

namespace App\Services\PHR\Import;

use DOMElement;
use DOMXPath;

/**
 * Reads lab results from a C-CDA Results section's structured Result Observations.
 *
 * The narrative table is a rendering whose column order differs by vendor, so
 * positional cells are a poor source of truth: one common layout puts the
 * analysis time where the narrative reader expects the reference range. The
 * structured entries carry every field explicitly.
 *
 * A reference range is read field by field and never as an element's whole text
 * content. `observationRange` commonly holds the same string twice, once as
 * `<text>` and once as `<value xsi:type="ST">`, and an IVL_PQ in a non-UCUM unit
 * repeats the unit in each bound's `translation/originalText`; concatenating the
 * subtree stores "NEGNEG" or "4.3 - 5.6 PERCENTPERCENTPERCENT".
 */
final class PhrCcdaResultObservations
{
    private const string RESULT_ORGANIZER = '2.16.840.1.113883.10.20.22.4.1';

    private const string RESULT_OBSERVATION = '2.16.840.1.113883.10.20.22.4.2';

    private readonly PhrCcdaXml $xml;

    public function __construct(private DOMXPath $xpath)
    {
        $this->xml = new PhrCcdaXml($xpath);
    }

    /**
     * @return list<array<string, string|bool|null>>
     */
    public function records(DOMElement $section): array
    {
        return array_values(array_filter(array_column($this->observations($section), 'record')));
    }

    /**
     * Each Result Observation's lab record, with the ids an earlier import may
     * have given the same result: `narrative_id`, the narrative-table reader's
     * id for the table row the observation references, and `source_id`, the
     * observation's own `root|extension`.
     *
     * `record` is null for an observation nothing names, not even a code. Its
     * `narrative_cells`, the table row it references, let the importer read it
     * as the narrative-table reader would rather than drop it.
     *
     * @return list<array{record: array<string, string|bool|null>|null, narrative_id: string|null, source_id: string|null, narrative_cells: list<string>|null}>
     */
    public function observations(DOMElement $section): array
    {
        $observations = [];
        $query = './/*[local-name()="entry"]//*[local-name()="observation"][*[local-name()="templateId"][@root="'.self::RESULT_OBSERVATION.'"]]';
        foreach ($this->xpath->query($query, $section) ?: [] as $observation) {
            if (! $observation instanceof DOMElement) {
                continue;
            }

            $cells = $this->narrativeCells($observation);
            $observations[] = [
                'record' => $this->record($observation),
                'narrative_id' => $cells === null ? null : PhrCcdaNarrativeRow::labExternalId($cells),
                'source_id' => $this->sourceId($observation),
                'narrative_cells' => $cells,
            ];
        }

        return $observations;
    }

    /**
     * The cells of the narrative-table row this observation's text or code
     * references, as the narrative-table reader takes them, or null when it
     * references none that reader would import.
     *
     * @return list<string>|null
     */
    private function narrativeCells(DOMElement $observation): ?array
    {
        $code = $this->xml->child($observation, 'code');
        foreach ([$this->xml->child($observation, 'text'), $code === null ? null : $this->xml->child($code, 'originalText')] as $element) {
            $reference = $element === null ? null : $this->xml->attribute($this->xml->child($element, 'reference'), 'value');
            $target = $reference !== null && str_starts_with($reference, '#') ? $this->xml->narrativeElement(substr($reference, 1)) : null;
            if ($target === null) {
                continue;
            }

            $row = $target->localName === 'tr' ? $target : $this->xml->first('ancestor::*[local-name()="tr"][1]', $target);
            // The rows that reader takes: those directly in a table or its tbody.
            if ($row === null || ! in_array($row->parentNode?->localName, ['tbody', 'table'], true)) {
                continue;
            }

            $cells = PhrCcdaNarrativeRow::cells($this->xpath, $row);

            return PhrCcdaNarrativeRow::isData($cells) ? $cells : null;
        }

        return null;
    }

    private function sourceId(DOMElement $observation): ?string
    {
        $id = $this->xml->child($observation, 'id');
        $root = $this->xml->attribute($id, 'root');

        return $root === null ? null : $root.'|'.($this->xml->attribute($id, 'extension') ?? '');
    }

    /**
     * @return array<string, string|bool|null>|null
     */
    private function record(DOMElement $observation): ?array
    {
        // Named by its code's display name or original text, else by the
        // narrative its own <text> points at, else by the code itself.
        $code = $this->xml->child($observation, 'code');
        $analyte = $this->xml->codeName($code)
            ?? $this->xml->edText($this->xml->child($observation, 'text'))
            ?? $this->xml->codeLabel($code);
        if ($analyte === null) {
            return null;
        }

        $organizer = $this->organizer($observation);
        [$value, $unit] = $this->value($this->xml->child($observation, 'value'));
        $observedAt = $this->time($this->xml->child($observation, 'effectiveTime'))
            ?? ($organizer === null ? null : $this->time($this->xml->child($organizer, 'effectiveTime')));

        return [
            'test_name' => ($organizer === null ? null : $this->xml->codeName($this->xml->child($organizer, 'code'))) ?? $analyte,
            'analyte' => $analyte,
            'observed_at' => $observedAt,
            'value' => $value,
            'unit' => $unit,
            ...$this->referenceRange($observation),
            // The observation's own interpretation; the range carries its own "N".
            'abnormal_flag' => $this->interpretation($this->xml->child($observation, 'interpretationCode')),
            'external_id' => 'ccda-lab-'.sha1(implode('|', $this->identity($observation, $analyte, $observedAt, $value, $unit))),
        ];
    }

    /**
     * @return array{reference_range_text: string|null, range_min: string|null, range_max: string|null, range_min_inclusive: bool|null, range_max_inclusive: bool|null, range_unit: string|null}
     */
    private function referenceRange(DOMElement $observation): array
    {
        $range = $this->observationRange($observation);
        if ($range === null) {
            return ['reference_range_text' => null, 'range_min' => null, 'range_max' => null, 'range_min_inclusive' => null, 'range_max_inclusive' => null, 'range_unit' => null];
        }

        $value = $this->xml->child($range, 'value');
        $type = $this->xml->type($value);
        [$low, $lowUnit, $lowInclusive] = $this->bound($value === null ? null : $this->xml->child($value, 'low'));
        [$high, $highUnit, $highInclusive] = $this->bound($value === null ? null : $this->xml->child($value, 'high'));
        $rangeUnit = $lowUnit ?? $highUnit;

        // The bounds and their unit are the range. Text is only what the source
        // wrote, its own text or else an ST value, kept as written; none is made
        // up from the bounds, so it never disagrees with them. A bound's unit goes
        // to range_unit and is never appended. A source whose text is only a unit
        // keeps that text; nothing structured marks it as a unit rather than a
        // qualitative range.
        $text = $this->xml->edText($this->xml->child($range, 'text'));
        if ($text === null && $type === 'ST') {
            $text = $this->xml->edText($value);
        }

        return [
            'reference_range_text' => $text,
            'range_min' => $low,
            'range_max' => $high,
            // Only what the bound itself says. HL7 reads an absent `inclusive` as
            // true, but sources commonly omit it from bounds their labs print as
            // "<200", so an omission is stored as unknown, not as inclusive.
            'range_min_inclusive' => $low === null ? null : $lowInclusive,
            'range_max_inclusive' => $high === null ? null : $highInclusive,
            'range_unit' => $rangeUnit,
        ];
    }

    /**
     * The range describing normal results, or the first one when none says so.
     */
    private function observationRange(DOMElement $observation): ?DOMElement
    {
        $first = null;
        foreach ($this->xml->children($observation, 'referenceRange') as $referenceRange) {
            $range = $this->xml->child($referenceRange, 'observationRange');
            if ($range === null) {
                continue;
            }
            $first ??= $range;
            $interpretation = $this->xml->attribute($this->xml->child($range, 'interpretationCode'), 'code');
            if ($interpretation === null || $interpretation === 'N') {
                return $range;
            }
        }

        return $first;
    }

    /**
     * A bound's number, unit and its stated inclusiveness: true or false when its
     * `inclusive` attribute says so, null when it has none. A PQ in a unit outside
     * UCUM is a nullFlavor PQ whose number and unit live in its translation.
     *
     * @return array{0: string|null, 1: string|null, 2: bool|null}
     */
    private function bound(?DOMElement $bound): array
    {
        if ($bound === null) {
            return [null, null, null];
        }

        // An xs:boolean: true, false, 1 or 0.
        $inclusive = ValueCoercion::boolean($this->xml->attribute($bound, 'inclusive'));
        $number = ValueCoercion::numeric($this->xml->attribute($bound, 'value'));
        $unit = $this->xml->attribute($bound, 'unit');
        $translation = $this->xml->child($bound, 'translation');
        if ($number === null && $translation !== null) {
            $number = ValueCoercion::numeric($this->xml->attribute($translation, 'value'));
            $unit ??= $this->xml->edText($this->xml->child($translation, 'originalText'));
        }

        return [$number, $unit, $inclusive];
    }

    private function boundsText(?string $low, bool $lowInclusive, ?string $high, bool $highInclusive): ?string
    {
        return match (true) {
            $low !== null && $high !== null => "{$low} - {$high}",
            $high !== null => ($highInclusive ? '<=' : '<').$high,
            $low !== null => ($lowInclusive ? '>=' : '>').$low,
            default => null,
        };
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function value(?DOMElement $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        return match ($this->xml->type($value)) {
            'PQ' => $this->quantity($value),
            'IVL_PQ', 'IVL_REAL', 'IVL_INT' => $this->interval($value),
            'ST', 'ED' => [$this->xml->edText($value), null],
            'CD', 'CE', 'CO', 'CV' => [$this->xml->codeName($value) ?? $this->xml->codeLabel($value), null],
            default => [ValueCoercion::string($this->xml->attribute($value, 'value')) ?? $this->xml->edText($value), null],
        };
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function quantity(DOMElement $value): array
    {
        $number = ValueCoercion::string($this->xml->attribute($value, 'value'));
        if ($number !== null) {
            return [$number, $this->xml->attribute($value, 'unit')];
        }

        $translation = $this->xml->child($value, 'translation');
        if ($translation === null) {
            return [null, null];
        }

        return [
            ValueCoercion::string($this->xml->attribute($translation, 'value')),
            $this->xml->edText($this->xml->child($translation, 'originalText')),
        ];
    }

    /**
     * A result reported as an interval ("<5"), in the range's notation.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function interval(DOMElement $value): array
    {
        [$low, $lowUnit, $lowInclusive] = $this->bound($this->xml->child($value, 'low'));
        [$high, $highUnit, $highInclusive] = $this->bound($this->xml->child($value, 'high'));
        // A value's text has always read an unstated bound as HL7 does, inclusive.
        $text = $this->boundsText($low, $lowInclusive ?? true, $high, $highInclusive ?? true);

        return [$text, $text === null ? null : ($lowUnit ?? $highUnit)];
    }

    /**
     * The interpretation's code ("H"), or its text when it has only that.
     */
    private function interpretation(?DOMElement $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return $this->xml->attribute($code, 'code') ?? $this->xml->edText($this->xml->child($code, 'originalText'));
    }

    /**
     * An HL7 TS (YYYYMMDD[HH[MM[SS[.S+]]]][+/-ZZZZ]) as an ISO 8601 string.
     */
    private function time(?DOMElement $effectiveTime): ?string
    {
        $value = $this->xml->attribute($effectiveTime, 'value')
            ?? ($effectiveTime === null ? null : $this->xml->attribute($this->xml->child($effectiveTime, 'low'), 'value'));
        if ($value === null || preg_match('/^(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})?(\d{2})?(?:\.\d+)?)?([+-]\d{4})?$/', $value, $m) !== 1) {
            return null;
        }

        $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        if (($m[4] ?? '') === '') {
            return $date;
        }

        $offset = ($m[7] ?? '') === '' ? '' : substr($m[7], 0, 3).':'.substr($m[7], 3);

        return sprintf('%sT%s:%s:%s%s', $date, $m[4], ($m[5] ?? '') ?: '00', ($m[6] ?? '') ?: '00', $offset);
    }

    /**
     * The observation's source id when it has one; otherwise what it says.
     *
     * @return list<string>
     */
    private function identity(DOMElement $observation, string $analyte, ?string $observedAt, ?string $value, ?string $unit): array
    {
        $id = $this->xml->child($observation, 'id');
        $root = $this->xml->attribute($id, 'root');
        if ($root !== null) {
            return ['id', $root, $this->xml->attribute($id, 'extension') ?? ''];
        }

        return ['content', $analyte, $observedAt ?? '', $value ?? '', $unit ?? ''];
    }

    private function organizer(DOMElement $observation): ?DOMElement
    {
        return $this->xml->first('ancestor::*[local-name()="organizer"][*[local-name()="templateId"][@root="'.self::RESULT_ORGANIZER.'"]][1]', $observation);
    }
}
