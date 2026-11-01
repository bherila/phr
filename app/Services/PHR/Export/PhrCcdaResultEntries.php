<?php

namespace App\Services\PHR\Export;

use App\Support\PHR\PhrLabReferenceRangeNotation;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;
use XMLWriter;

/**
 * The Results section's structured entries: Result Organizers (C-CDA 5.0,
 * 2.16.840.1.113883.10.20.22.4.1:2023-05-01) holding Result Observations
 * (2.16.840.1.113883.10.20.22.4.2:2023-05-01), written beside the narrative table
 * they render.
 *
 * - A value is a PQ when it is a plain number with a unit, an IVL_PQ when it is a
 *   comparison ("<5 mg/dL"), else an ST. A number with no unit is an ST rather than a
 *   PQ with an invented unit. A unit containing whitespace cannot be a PQ's @unit, so
 *   it travels as the translation's text and that PQ lacks the @unit Schematron wants.
 * - A reference range is an IVL_PQ whose bounds carry `inclusive` where the lab
 *   records it. Text appears in observationRange only when the source wrote it, so
 *   a range stored as bounds is never read back as text.
 * - Each observation's text references its narrative row, and its id is stable for
 *   a lab, so a re-import updates the same row.
 *
 * The labs hold no LOINC code, so each code is a null-flavored code whose
 * originalText names the analyte; the importer reads the name from there.
 */
final class PhrCcdaResultEntries
{
    private const string OBSERVATION_INTERPRETATION = '2.16.840.1.113883.5.83';

    /** Base namespace of the stable ids this exporter derives for results. */
    private const string ID_NAMESPACE = '6f3b1c52-8d4e-4f6a-9b27-3c5a1e9d7b04';

    /** The namespace of this deployment's result ids; see idNamespace(). */
    private string $idNamespace;

    private const string ORGANIZER_TEMPLATE = '2.16.840.1.113883.10.20.22.4.1';

    private const string OBSERVATION_TEMPLATE = '2.16.840.1.113883.10.20.22.4.2';

    private const string TEMPLATE_VERSION = '2023-05-01';

    /** CDAObservationInterpretation. */
    private const array INTERPRETATIONS = ['B', 'D', 'U', 'W', '<', '>', 'A', 'AA', 'HH', 'LL', 'H', 'L', 'N', 'I', 'MS', 'R', 'S', 'VS'];

    /** The Result Status value set, keyed by the statuses a lab row may carry. */
    private const array STATUSES = [
        'aborted' => 'aborted',
        'active' => 'active',
        'preliminary' => 'active',
        'pending' => 'active',
        'registered' => 'active',
        'in-progress' => 'active',
        'p' => 'active',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'entered-in-error' => 'cancelled',
        'x' => 'cancelled',
        'held' => 'held',
        'suspended' => 'suspended',
    ];

    /**
     * @param  string  $deploymentKey  a secret unique to this deployment (the application key);
     *                                 empty only where there is none, as in the synthetic fixture
     */
    public function __construct(string $deploymentKey = '')
    {
        // Row ids are only unique within a deployment, so two installations' exports of
        // "patient 1, lab 1" must not name the same result. A keyed hash makes the
        // namespace deployment-specific and stable across exports, and the key itself is
        // never recoverable from the ids.
        $this->idNamespace = $deploymentKey === ''
            ? self::ID_NAMESPACE
            : Uuid::uuid5(self::ID_NAMESPACE, hash_hmac('sha256', 'phr-ccda-result-ids', $deploymentKey))->toString();
    }

    /**
     * Narrative-row ids, in lab order: the id a lab's `tr` carries and its
     * observation's text references.
     */
    public static function rowId(int $index): string
    {
        return 'labresult-'.($index + 1);
    }

    /**
     * One organizer per panel (labs sharing a test name and effective time),
     * in the order the panels first appear.
     *
     * @param  list<object>  $labs  in narrative-row order
     */
    public function write(XMLWriter $xml, array $labs): void
    {
        $panels = [];
        foreach ($labs as $index => $lab) {
            $name = $this->text($lab->test_name ?? null) ?? $this->text($lab->analyte ?? null) ?? 'Lab result';
            $panels[implode("\0", [$name, $this->time($lab) ?? '', $this->status($lab)])][] = [$index, $lab, $name];
        }

        foreach ($panels as $members) {
            $this->organizer($xml, $members);
        }
    }

    /**
     * @param  list<array{0: int, 1: object, 2: string}>  $members
     */
    private function organizer(XMLWriter $xml, array $members): void
    {
        [, $first, $name] = $members[0];

        $xml->startElement('entry');
        $xml->writeAttribute('typeCode', 'DRIV');
        $xml->startElement('organizer');
        $xml->writeAttribute('classCode', 'BATTERY');
        $xml->writeAttribute('moodCode', 'EVN');
        $this->template($xml, self::ORGANIZER_TEMPLATE);
        $this->id($xml, 'organizer', $first);
        $this->nullCode($xml, $name);
        $this->statusCode($xml, $first);
        foreach ($members as [$index, $lab]) {
            $xml->startElement('component');
            $this->observation($xml, $index, $lab);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
    }

    private function observation(XMLWriter $xml, int $index, object $lab): void
    {
        $xml->startElement('observation');
        $xml->writeAttribute('classCode', 'OBS');
        $xml->writeAttribute('moodCode', 'EVN');
        $this->template($xml, self::OBSERVATION_TEMPLATE);
        $this->id($xml, 'observation', $lab);
        $this->nullCode($xml, $this->text($lab->analyte ?? null) ?? $this->text($lab->test_name ?? null) ?? 'Lab result');
        $xml->startElement('text');
        $xml->startElement('reference');
        $xml->writeAttribute('value', '#'.self::rowId($index));
        $xml->endElement();
        $xml->endElement();
        $this->statusCode($xml, $lab);
        $this->effectiveTime($xml, $lab);
        $this->value($xml, $lab);
        $this->interpretation($xml, $this->text($lab->abnormal_flag ?? null));
        $this->referenceRange($xml, $lab);
        $xml->endElement();
    }

    /**
     * A stable id for the lab, so a later export names the same result the same
     * way. It is derived from the lab's identity, never the database key itself.
     */
    private function id(XMLWriter $xml, string $kind, object $lab): void
    {
        $key = isset($lab->id, $lab->patient_id)
            ? "{$lab->patient_id}:{$lab->id}"
            : sha1(implode('|', [
                $this->text($lab->test_name ?? null), $this->text($lab->analyte ?? null),
                $this->time($lab), $this->text($lab->value ?? null), $this->text($lab->unit ?? null),
            ]));

        $xml->startElement('id');
        $xml->writeAttribute('root', Uuid::uuid5($this->idNamespace, "lab-{$kind}:{$key}")->toString());
        $xml->endElement();
    }

    /** The code is unknown (no LOINC is stored); its original text is the name. */
    private function nullCode(XMLWriter $xml, string $name): void
    {
        $xml->startElement('code');
        $xml->writeAttribute('nullFlavor', 'UNK');
        $xml->writeElement('originalText', $name);
        $xml->endElement();
    }

    private function statusCode(XMLWriter $xml, object $lab): void
    {
        $xml->startElement('statusCode');
        $xml->writeAttribute('code', $this->status($lab));
        $xml->endElement();
    }

    /** A Result Status code; anything else a result carries ("final", "corrected") is a finished result. */
    private function status(object $lab): string
    {
        $status = $this->text($lab->result_status ?? null);

        return self::STATUSES[strtolower($status ?? '')] ?? 'completed';
    }

    private function effectiveTime(XMLWriter $xml, object $lab): void
    {
        $xml->startElement('effectiveTime');
        $time = $this->time($lab);
        if ($time === null) {
            $xml->writeAttribute('nullFlavor', 'UNK');
        } else {
            $xml->writeAttribute('value', $time);
        }
        $xml->endElement();
    }

    /** The narrative Date's moment: a day when no time of day was recorded. */
    private function time(object $lab): ?string
    {
        $moment = $lab->result_datetime ?? $lab->collection_datetime ?? null;
        if (! $moment instanceof CarbonInterface) {
            return null;
        }

        return $moment->equalTo($moment->copy()->startOfDay()) ? $moment->format('Ymd') : $moment->format('YmdHisO');
    }

    private function value(XMLWriter $xml, object $lab): void
    {
        $raw = $this->text($lab->value ?? null);
        $unit = $this->text($lab->unit ?? null);
        $text = $raw ?? PhrLabReferenceRangeNotation::number($lab->value_numeric ?? null);

        $xml->startElement('value');
        $number = $text === null ? null : $this->plainNumber($text);
        if ($number !== null && $unit !== null) {
            $xml->writeAttribute('xsi:type', 'PQ');
            if ($this->isUnit($unit)) {
                $xml->writeAttribute('value', $number);
                $xml->writeAttribute('unit', $unit);
            } else {
                // A unit that is not a UCUM token cannot be a PQ's @unit: the number and the
                // unit as written travel in the translation, which the importer reads back.
                $xml->writeAttribute('nullFlavor', 'OTH');
                $xml->startElement('translation');
                $xml->writeAttribute('value', $number);
                $xml->writeElement('originalText', $unit);
                $xml->endElement();
            }
        } elseif ($text !== null && $unit !== null && preg_match('/^(<=|>=|<|>)\s*(\S+)$/', $text, $m) === 1 && ($bound = $this->plainNumber($m[2])) !== null) {
            // "<5" is an interval open on one side, in the same notation the importer reads back.
            $xml->writeAttribute('xsi:type', 'IVL_PQ');
            $this->bound($xml, $m[1][0] === '<' ? 'high' : 'low', $bound, $unit, strlen($m[1]) === 2);
        } else {
            $xml->writeAttribute('xsi:type', 'ST');
            if ($text === null) {
                $xml->writeAttribute('nullFlavor', 'NI');
            } else {
                $xml->text($text);
            }
        }
        $xml->endElement();
    }

    /**
     * A number the importer accepts (".5", "1,234", "1e3") as the plain decimal a PQ's
     * value is, or null when the text is not a number.
     */
    private function plainNumber(string $text): ?string
    {
        $number = str_replace(',', '', trim($text));
        if (! is_numeric($number)) {
            return null;
        }
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d+))?$/', $number, $m) === 1) {
            return ($m[1] === '-' ? '-' : '').($m[2] === '' ? '0' : $m[2]).(($m[3] ?? '') === '' ? '' : '.'.$m[3]);
        }

        // An exponent or a trailing point.
        $plain = rtrim(rtrim(sprintf('%.10F', (float) $number), '0'), '.');

        return $plain === '' || $plain === '-' ? '0' : $plain;
    }

    private function interpretation(XMLWriter $xml, ?string $flag): void
    {
        if ($flag === null) {
            return;
        }

        $xml->startElement('interpretationCode');
        if (in_array(strtoupper($flag), self::INTERPRETATIONS, true)) {
            $xml->writeAttribute('code', strtoupper($flag));
            $xml->writeAttribute('codeSystem', self::OBSERVATION_INTERPRETATION);
        } else {
            // Free text the source wrote: kept as written rather than guessed at.
            $xml->writeAttribute('nullFlavor', 'OTH');
            $xml->writeElement('originalText', $flag);
        }
        $xml->endElement();
    }

    private function referenceRange(XMLWriter $xml, object $lab): void
    {
        $min = PhrLabReferenceRangeNotation::number($lab->range_min ?? null);
        $max = PhrLabReferenceRangeNotation::number($lab->range_max ?? null);
        $text = $this->text($lab->reference_range_text ?? null);
        if ($min === null && $max === null && $text === null) {
            return;
        }

        $unit = $this->text($lab->range_unit ?? null);
        $xml->startElement('referenceRange');
        $xml->writeAttribute('typeCode', 'REFV');
        $xml->startElement('observationRange');
        if ($text !== null) {
            $xml->writeElement('text', $text);
        }
        $xml->startElement('value');
        $xml->writeAttribute('xsi:type', 'IVL_PQ');
        if ($min === null && $max === null) {
            // Only the source's words: the structured range is not known.
            $xml->writeAttribute('nullFlavor', 'NI');
        }
        if ($min !== null) {
            $this->bound($xml, 'low', $min, $unit, $this->inclusive($lab->range_min_inclusive ?? null));
        }
        if ($max !== null) {
            $this->bound($xml, 'high', $max, $unit, $this->inclusive($lab->range_max_inclusive ?? null));
        }
        $xml->endElement();
        $xml->startElement('interpretationCode');
        $xml->writeAttribute('code', 'N');
        $xml->writeAttribute('codeSystem', self::OBSERVATION_INTERPRETATION);
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * A bound of an interval. `inclusive` is written only when it is known; HL7
     * reads an absent one as inclusive, which is not what unknown means here.
     * A unit that is not UCUM-shaped is carried as the PQ's translation text.
     */
    private function bound(XMLWriter $xml, string $element, string $number, ?string $unit, ?bool $inclusive): void
    {
        $xml->startElement($element);
        $translated = $unit !== null && ! $this->isUnit($unit);
        if ($translated) {
            $xml->writeAttribute('nullFlavor', 'OTH');
        } else {
            $xml->writeAttribute('value', $number);
            if ($unit !== null) {
                $xml->writeAttribute('unit', $unit);
            }
        }
        if ($inclusive !== null) {
            $xml->writeAttribute('inclusive', $inclusive ? 'true' : 'false');
        }
        if ($translated) {
            $xml->startElement('translation');
            $xml->writeAttribute('value', $number);
            $xml->writeElement('originalText', $unit);
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function inclusive(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** A unit attribute is a token: no whitespace. */
    private function isUnit(string $unit): bool
    {
        return preg_match('/^\S+$/', $unit) === 1;
    }

    private function template(XMLWriter $xml, string $root): void
    {
        $xml->startElement('templateId');
        $xml->writeAttribute('root', $root);
        $xml->writeAttribute('extension', self::TEMPLATE_VERSION);
        $xml->endElement();
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
