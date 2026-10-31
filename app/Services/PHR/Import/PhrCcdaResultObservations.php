<?php

namespace App\Services\PHR\Import;

use DOMElement;
use DOMNode;
use DOMText;
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
    private const string XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const string RESULT_ORGANIZER = '2.16.840.1.113883.10.20.22.4.1';

    private const string RESULT_OBSERVATION = '2.16.840.1.113883.10.20.22.4.2';

    /** Narrative elements whose children are separate lines, not one run of text. */
    private const array BLOCK_ELEMENTS = ['paragraph', 'br', 'item', 'list', 'tr', 'td', 'th', 'table', 'tbody', 'thead'];

    public function __construct(private DOMXPath $xpath) {}

    /**
     * @return list<array<string, string|null>>
     */
    public function records(DOMElement $section): array
    {
        $records = [];
        $query = './/*[local-name()="entry"]//*[local-name()="observation"][*[local-name()="templateId"][@root="'.self::RESULT_OBSERVATION.'"]]';
        foreach ($this->xpath->query($query, $section) ?: [] as $observation) {
            if (! $observation instanceof DOMElement) {
                continue;
            }

            $record = $this->record($observation);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function record(DOMElement $observation): ?array
    {
        $analyte = $this->codeName($this->child($observation, 'code'));
        if ($analyte === null) {
            return null;
        }

        $organizer = $this->organizer($observation);
        [$value, $unit] = $this->value($this->child($observation, 'value'));
        $observedAt = $this->time($this->child($observation, 'effectiveTime'))
            ?? ($organizer === null ? null : $this->time($this->child($organizer, 'effectiveTime')));

        return [
            'test_name' => ($organizer === null ? null : $this->codeName($this->child($organizer, 'code'))) ?? $analyte,
            'analyte' => $analyte,
            'observed_at' => $observedAt,
            'value' => $value,
            'unit' => $unit,
            ...$this->referenceRange($observation),
            // The observation's own interpretation; the range carries its own "N".
            'abnormal_flag' => $this->attribute($this->child($observation, 'interpretationCode'), 'code'),
            'external_id' => 'ccda-lab-'.sha1(implode('|', $this->identity($observation, $analyte, $observedAt, $value, $unit))),
        ];
    }

    /**
     * @return array{reference_range_text: string|null, range_min: string|null, range_max: string|null, range_unit: string|null}
     */
    private function referenceRange(DOMElement $observation): array
    {
        $range = $this->observationRange($observation);
        if ($range === null) {
            return ['reference_range_text' => null, 'range_min' => null, 'range_max' => null, 'range_unit' => null];
        }

        $value = $this->child($range, 'value');
        $type = $this->type($value);
        [$low, $lowUnit, $lowInclusive] = $this->bound($value === null ? null : $this->child($value, 'low'));
        [$high, $highUnit, $highInclusive] = $this->bound($value === null ? null : $this->child($value, 'high'));
        $rangeUnit = $lowUnit ?? $highUnit;

        // Prefer the range's own text, then an ST value, then the bounds. One source
        // only, kept as the source wrote it: a bound's unit goes to range_unit and
        // is never appended. A source whose text is only a unit keeps that text;
        // nothing structured marks it as a unit rather than a qualitative range.
        $text = $this->edText($this->child($range, 'text'));
        if ($text === null && $type === 'ST' && $value !== null) {
            $text = $this->plainText($value);
        }
        if ($text === null) {
            $text = $this->boundsText($low, $lowInclusive, $high, $highInclusive);
        }

        return [
            'reference_range_text' => $text,
            'range_min' => $low,
            'range_max' => $high,
            'range_unit' => $rangeUnit,
        ];
    }

    /**
     * The range describing normal results, or the first one when none says so.
     */
    private function observationRange(DOMElement $observation): ?DOMElement
    {
        $first = null;
        foreach ($this->children($observation, 'referenceRange') as $referenceRange) {
            $range = $this->child($referenceRange, 'observationRange');
            if ($range === null) {
                continue;
            }
            $first ??= $range;
            $interpretation = $this->attribute($this->child($range, 'interpretationCode'), 'code');
            if ($interpretation === null || $interpretation === 'N') {
                return $range;
            }
        }

        return $first;
    }

    /**
     * A bound's number, unit and inclusiveness. A PQ in a unit outside UCUM is a
     * nullFlavor PQ whose number and unit live in its translation.
     *
     * @return array{0: string|null, 1: string|null, 2: bool}
     */
    private function bound(?DOMElement $bound): array
    {
        if ($bound === null) {
            return [null, null, true];
        }

        $inclusive = $this->attribute($bound, 'inclusive') !== 'false';
        $number = ValueCoercion::numeric($this->attribute($bound, 'value'));
        $unit = $this->attribute($bound, 'unit');
        $translation = $this->child($bound, 'translation');
        if ($number === null && $translation !== null) {
            $number = ValueCoercion::numeric($this->attribute($translation, 'value'));
            $unit ??= $this->edText($this->child($translation, 'originalText'));
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

        return match ($this->type($value)) {
            'PQ' => $this->quantity($value),
            'IVL_PQ', 'IVL_REAL', 'IVL_INT' => $this->interval($value),
            'ST', 'ED' => [$this->plainText($value), null],
            'CD', 'CE', 'CO', 'CV' => [$this->codeName($value), null],
            default => [ValueCoercion::string($this->attribute($value, 'value')) ?? $this->plainText($value), null],
        };
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function quantity(DOMElement $value): array
    {
        $number = ValueCoercion::string($this->attribute($value, 'value'));
        if ($number !== null) {
            return [$number, $this->attribute($value, 'unit')];
        }

        $translation = $this->child($value, 'translation');
        if ($translation === null) {
            return [null, null];
        }

        return [
            ValueCoercion::string($this->attribute($translation, 'value')),
            $this->edText($this->child($translation, 'originalText')),
        ];
    }

    /**
     * A result reported as an interval ("<5"), in the range's notation.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function interval(DOMElement $value): array
    {
        [$low, $lowUnit, $lowInclusive] = $this->bound($this->child($value, 'low'));
        [$high, $highUnit, $highInclusive] = $this->bound($this->child($value, 'high'));
        $text = $this->boundsText($low, $lowInclusive, $high, $highInclusive);

        return [$text, $text === null ? null : ($lowUnit ?? $highUnit)];
    }

    private function codeName(?DOMElement $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return $this->attribute($code, 'displayName')
            ?? $this->edText($this->child($code, 'originalText'))
            ?? $this->attribute($this->child($code, 'translation'), 'displayName');
    }

    /**
     * Text of an ED-typed element (`text`, `originalText`): its reference into
     * the narrative when it has one, otherwise its own text.
     */
    private function edText(?DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $reference = $this->attribute($this->child($element, 'reference'), 'value');
        if ($reference !== null && str_starts_with($reference, '#')) {
            $target = $this->narrativeElement(substr($reference, 1));
            if ($target !== null) {
                return $this->plainText($target);
            }
        }

        return $this->plainText($element, skipReference: true);
    }

    private function narrativeElement(string $id): ?DOMElement
    {
        if ($id === '' || str_contains($id, '"')) {
            return null;
        }

        return $this->first('//*[@ID="'.$id.'"]');
    }

    /**
     * Text of one element with block-level children kept apart, whitespace
     * collapsed, and an empty result as null.
     */
    private function plainText(DOMNode $node, bool $skipReference = false): ?string
    {
        $parts = [];
        $this->collectText($node, $parts, $skipReference);
        $text = preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '';

        return ValueCoercion::string($text);
    }

    /**
     * @param  list<string>  $parts
     */
    private function collectText(DOMNode $node, array &$parts, bool $skipReference): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $parts[] = $child->data;
            } elseif ($child instanceof DOMElement) {
                if ($skipReference && $child->localName === 'reference') {
                    continue;
                }
                $block = in_array($child->localName, self::BLOCK_ELEMENTS, true);
                if ($block) {
                    $parts[] = ' ';
                }
                $this->collectText($child, $parts, $skipReference);
                if ($block) {
                    $parts[] = ' ';
                }
            }
        }
    }

    /**
     * An HL7 TS (YYYYMMDD[HH[MM[SS[.S+]]]][+/-ZZZZ]) as an ISO 8601 string.
     */
    private function time(?DOMElement $effectiveTime): ?string
    {
        $value = $this->attribute($effectiveTime, 'value')
            ?? ($effectiveTime === null ? null : $this->attribute($this->child($effectiveTime, 'low'), 'value'));
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
        $id = $this->child($observation, 'id');
        $root = $this->attribute($id, 'root');
        if ($root !== null) {
            return ['id', $root, $this->attribute($id, 'extension') ?? ''];
        }

        return ['content', $analyte, $observedAt ?? '', $value ?? '', $unit ?? ''];
    }

    private function organizer(DOMElement $observation): ?DOMElement
    {
        return $this->first('ancestor::*[local-name()="organizer"][*[local-name()="templateId"][@root="'.self::RESULT_ORGANIZER.'"]][1]', $observation);
    }

    private function first(string $query, ?DOMNode $context = null): ?DOMElement
    {
        $matches = $this->xpath->query($query, $context);
        $match = $matches === false ? null : $matches->item(0);

        return $match instanceof DOMElement ? $match : null;
    }

    private function type(?DOMElement $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $type = $element->getAttributeNS(self::XSI, 'type');
        if ($type === '') {
            $type = $element->getAttribute('xsi:type');
        }
        // A prefixed type ("v3:PQ") names the same datatype.
        $type = substr((string) strrchr(':'.$type, ':'), 1);

        return $type === '' ? null : strtoupper($type);
    }

    private function attribute(?DOMElement $element, string $name): ?string
    {
        if ($element === null || ! $element->hasAttribute($name)) {
            return null;
        }

        return ValueCoercion::string($element->getAttribute($name));
    }

    private function child(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($this->children($parent, $localName) as $child) {
            return $child;
        }

        return null;
    }

    /**
     * @return iterable<DOMElement>
     */
    private function children(DOMElement $parent, string $localName): iterable
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                yield $child;
            }
        }
    }
}
