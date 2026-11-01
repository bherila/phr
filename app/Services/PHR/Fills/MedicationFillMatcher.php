<?php

namespace App\Services\PHR\Fills;

use App\Models\PhrMedicationFill;
use App\Models\PhrPatient;
use App\Support\PHR\PhrRecordLifecycle;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides whether a fill about to be written is one the patient already has,
 * whichever source it was read from (a label, a receipt, a statement, an EOB
 * line). This is the cross-source half of "no duplicates"; the same-source half
 * is the external_id the write path derives from pharmacy, Rx number and date.
 *
 * Only fills on the same day are candidates. Against each:
 *  - same Rx number (and the pharmacy and drug do not contradict it): the same fill;
 *  - no Rx number on one side, and same drug, pharmacy and quantity: the same fill;
 *  - same drug but a contradiction or a gap the reader cannot settle: uncertain,
 *    which the write path records as a flagged fill for a person, never silently
 *    merged and never silently duplicated;
 *  - anything else is a different fill (a second prescription, a different drug).
 */
final class MedicationFillMatcher
{
    public const string NONE = 'none';

    public const string MATCHED = 'matched';

    public const string UNCERTAIN = 'uncertain';

    private const string SAME = 'same';

    private const string DIFFERENT = 'different';

    private const string UNKNOWN = 'unknown';

    /** The same drug named differently or less completely: worth a person's look, never a silent merge. */
    private const string PARTIAL = 'partial';

    /** Words that say what a pharmacy or a dosage form is, not which one. */
    private const array NOISE = [
        'pharmacy', 'pharmacies', 'inc', 'llc', 'co', 'corp', 'the', 'store', 'drug', 'drugs', 'rx',
        'film', 'coated', 'oral', 'release', 'and', 'for', 'with', 'hcl', 'hydrochloride', 'sodium', 'potassium', 'calcium', 'magnesium',
        'trihydrate', 'dihydrate', 'monohydrate', 'sulfate', 'phosphate', 'acetate', 'citrate', 'tartrate', 'succinate', 'mesylate', 'maleate',
    ];

    /**
     * @param  array<string, mixed>  $incoming  columns of the fill being written (filled_on, quantity, rx_number, pharmacy_name, ndc_11, drug_as_printed, brand_name, substance_names, strengths)
     * @return array{status: string, fill: ?PhrMedicationFill, by: ?string}
     */
    public function find(PhrPatient $patient, array $incoming, ?int $excludeFillId = null): array
    {
        $day = isset($incoming['filled_on']) ? substr((string) $incoming['filled_on'], 0, 10) : '';
        if ($day === '') {
            return ['status' => self::NONE, 'fill' => null, 'by' => null];
        }

        $query = PhrMedicationFill::query()
            ->where('patient_id', $patient->id)
            ->whereDate('filled_on', $day)
            ->where('review_status', '!=', PhrReviewStatus::REJECTED)
            ->orderBy('id');
        PhrRecordLifecycle::scopeLive($query);
        if ($excludeFillId !== null) {
            $query->whereKeyNot($excludeFillId);
        }
        /** @var Collection<int, PhrMedicationFill> $candidates */
        $candidates = $query->lockForUpdate()->get();

        $matched = [];
        $uncertain = [];
        foreach ($candidates as $candidate) {
            [$verdict, $by] = $this->compare($incoming, $candidate);
            if ($verdict === self::MATCHED) {
                $matched[] = [$candidate, $by];
            } elseif ($verdict === self::UNCERTAIN) {
                $uncertain[] = [$candidate, $by];
            }
        }

        if (count($matched) === 1) {
            return ['status' => self::MATCHED, 'fill' => $matched[0][0], 'by' => $matched[0][1]];
        }
        // Two fills that both look like it: the data cannot say which, so a person must.
        $unsure = [...$matched, ...$uncertain];
        if ($unsure !== []) {
            return ['status' => self::UNCERTAIN, 'fill' => $unsure[0][0], 'by' => $unsure[0][1]];
        }

        return ['status' => self::NONE, 'fill' => null, 'by' => null];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array{0: string, 1: ?string}
     */
    public function compare(array $incoming, PhrMedicationFill $existing): array
    {
        $rx = $this->rx($incoming['rx_number'] ?? null, $existing->rx_number);
        $pharmacy = $this->pharmacy($incoming['pharmacy_name'] ?? null, $existing->pharmacy_name);
        $drug = $this->drug($incoming, $existing);
        $quantity = $this->quantity($incoming['quantity'] ?? null, $existing->quantity);
        $sameDrug = $drug === self::SAME || $drug === self::PARTIAL;

        if ($rx === self::SAME) {
            return $pharmacy !== self::DIFFERENT && $drug !== self::DIFFERENT
                ? [self::MATCHED, 'rx_number']
                : [self::UNCERTAIN, 'rx_number'];
        }
        if ($rx === self::DIFFERENT) {
            // Two prescriptions filled the same day are normally two fills; only a perfect
            // match on everything else makes it worth a second look.
            return $drug === self::SAME && $pharmacy === self::SAME && $quantity === self::SAME
                ? [self::UNCERTAIN, 'attributes']
                : [self::NONE, null];
        }
        // Without an Rx number or an NDC on both sides, a confident match needs the drug named
        // identically (same words, strengths and form); a fuller or shorter name is a flag.
        if ($drug === self::SAME && $pharmacy === self::SAME && $quantity === self::SAME) {
            return [self::MATCHED, 'attributes'];
        }
        if ($sameDrug && ! ($pharmacy === self::DIFFERENT && $quantity === self::DIFFERENT)) {
            return [self::UNCERTAIN, 'attributes'];
        }

        return [self::NONE, null];
    }

    private function rx(mixed $a, mixed $b): string
    {
        $a = $this->rxKey($a);
        $b = $this->rxKey($b);
        if ($a === '' || $b === '') {
            return self::UNKNOWN;
        }

        return $a === $b ? self::SAME : self::DIFFERENT;
    }

    private function rxKey(mixed $value): string
    {
        return is_scalar($value) ? ltrim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $value)), '0') : '';
    }

    private function pharmacy(mixed $a, mixed $b): string
    {
        $a = $this->words($a);
        $b = $this->words($b);
        if ($a === [] || $b === []) {
            return self::UNKNOWN;
        }

        // "CVS" and "CVS Pharmacy #1234" name the same pharmacy; "CVS #12" and "Walgreens #12" do not.
        return array_diff($a, $b) === [] || array_diff($b, $a) === [] ? self::SAME : self::DIFFERENT;
    }

    /** @param  array<string, mixed>  $incoming */
    private function drug(array $incoming, PhrMedicationFill $existing): string
    {
        $incomingNdc = is_string($incoming['ndc_11'] ?? null) && $incoming['ndc_11'] !== '' ? $incoming['ndc_11'] : null;
        if ($incomingNdc !== null && $existing->ndc_11 !== null) {
            return $incomingNdc === $existing->ndc_11 ? self::SAME : self::DIFFERENT;
        }

        $mine = $this->profile($incoming['drug_as_printed'] ?? null, $incoming['brand_name'] ?? null, $incoming['substance_names'] ?? null, $incoming['strengths'] ?? null);
        $theirs = $this->profile($existing->drug_as_printed, $existing->brand_name, $existing->substance_names, $existing->strengths);
        if ($mine['names'] === [] || $theirs['names'] === []) {
            return self::UNKNOWN;
        }
        if (array_intersect($mine['names'], $theirs['names']) === []) {
            return self::DIFFERENT;
        }
        // Each of these makes it another product: a release formulation (metformin, metformin ER),
        // every component's strength and concentration (500 mg/125 mg, 4 mg and 4 mg/ml), the form.
        if ($mine['qualifiers'] !== $theirs['qualifiers']) {
            return self::DIFFERENT;
        }
        if ($mine['strengths'] !== [] && $theirs['strengths'] !== [] && $mine['strengths'] !== $theirs['strengths']) {
            return self::DIFFERENT;
        }
        if ($mine['forms'] !== [] && $theirs['forms'] !== [] && $mine['forms'] !== $theirs['forms']) {
            return self::DIFFERENT;
        }

        // The same only if the two names say the same thing; otherwise it is the same drug named
        // differently or less completely, which a person decides.
        return $mine === $theirs ? self::SAME : self::PARTIAL;
    }

    /**
     * What a drug name says, in comparable parts.
     *
     * @return array{names: list<string>, qualifiers: list<string>, strengths: list<string>, forms: list<string>}
     */
    private function profile(mixed ...$sources): array
    {
        $names = [];
        $qualifiers = [];
        $strengths = [];
        $forms = [];
        foreach ($sources as $source) {
            foreach (is_array($source) ? $source : [$source] as $text) {
                if (! is_scalar($text)) {
                    continue;
                }
                $text = strtolower((string) $text);
                // Strengths first, with the concentration denominator: "4 mg/ml" is not "4 mg".
                $text = (string) preg_replace_callback(
                    '/(\d+(?:\.\d+)?)\s*(mg|mcg|ug|g|ml|l|units?|iu|%)(?:\s*\/\s*(\d+(?:\.\d+)?)?\s*(ml|g|mg|l|hr|h|actuation|dose))?/',
                    static function (array $match) use (&$strengths): string {
                        $denominator = $match[4] === null ? '' : '/'.($match[3] === null ? '' : (string) (float) $match[3]).$match[4];
                        $strengths[] = (string) (float) $match[1].$match[2].$denominator;

                        return ' ';
                    },
                    $text,
                    -1,
                    $count,
                    PREG_UNMATCHED_AS_NULL,
                );
                foreach (preg_split('/[^a-z]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    match (true) {
                        in_array($word, ['extended', 'er', 'xr', 'xl', 'sr', 'cr', 'la', 'prolonged'], true) => $qualifiers[] = 'extended',
                        in_array($word, ['delayed', 'dr', 'ec', 'enteric'], true) => $qualifiers[] = 'delayed',
                        in_array($word, ['odt', 'disintegrating', 'chewable', 'chew'], true) => $qualifiers[] = 'disintegrating',
                        in_array($word, ['tablet', 'tablets', 'tab', 'tabs'], true) => $forms[] = 'tablet',
                        in_array($word, ['capsule', 'capsules', 'cap', 'caps'], true) => $forms[] = 'capsule',
                        in_array($word, ['injection', 'inj', 'injectable'], true) => $forms[] = 'injection',
                        in_array($word, ['solution', 'soln', 'suspension', 'syrup', 'liquid', 'cream', 'ointment', 'gel', 'patch', 'inhaler', 'spray', 'drops'], true) => $forms[] = $word === 'soln' ? 'solution' : $word,
                        in_array($word, self::NOISE, true) || strlen($word) < 3 => null,
                        default => $names[] = $word,
                    };
                }
            }
        }
        $unique = static function (array $values): array {
            $values = array_values(array_unique($values));
            sort($values);

            return $values;
        };

        return ['names' => $unique($names), 'qualifiers' => $unique($qualifiers), 'strengths' => $unique($strengths), 'forms' => $unique($forms)];
    }

    private function quantity(mixed $a, mixed $b): string
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return self::UNKNOWN;
        }

        return abs((float) $a - (float) $b) < 0.0005 ? self::SAME : self::DIFFERENT;
    }

    /** @return list<string> */
    private function words(mixed $value): array
    {
        if (! is_scalar($value)) {
            return [];
        }
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff(array_unique($words), self::NOISE));
    }

    /** @return list<string> */
    /** @return list<string> strengths as "20mg", "2.5ml" */
}
