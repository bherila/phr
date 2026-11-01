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

    /** Words that say what a pharmacy or a dosage form is, not which one. */
    private const array NOISE = [
        'pharmacy', 'pharmacies', 'inc', 'llc', 'co', 'corp', 'the', 'store', 'drug', 'drugs', 'rx',
        'tab', 'tabs', 'tablet', 'tablets', 'cap', 'caps', 'capsule', 'capsules', 'film', 'coated', 'oral',
        'solution', 'suspension', 'and', 'for', 'with', 'hcl', 'hydrochloride',
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
        if ($drug === self::SAME && $pharmacy === self::SAME && $quantity === self::SAME) {
            return [self::MATCHED, 'attributes'];
        }
        if ($drug === self::SAME && ! ($pharmacy === self::DIFFERENT && $quantity === self::DIFFERENT)) {
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

        $mine = $this->drugWords($incoming['drug_as_printed'] ?? null, $incoming['brand_name'] ?? null, $incoming['substance_names'] ?? null);
        $theirs = $this->drugWords($existing->drug_as_printed, $existing->brand_name, $existing->substance_names);
        if ($mine === [] || $theirs === []) {
            return self::UNKNOWN;
        }
        // A different release formulation is a different product, though its name is a superset.
        if ($this->qualifiers($incoming['drug_as_printed'] ?? null, $incoming['brand_name'] ?? null, $incoming['substance_names'] ?? null)
            !== $this->qualifiers($existing->drug_as_printed, $existing->brand_name, $existing->substance_names)) {
            return self::DIFFERENT;
        }
        // Every identifying word of the shorter name must be in the longer one ("atorvastatin
        // calcium" is "atorvastatin"; "levothyroxine sodium" is not "liothyronine sodium", though
        // both say sodium). A partial overlap settles nothing, so it is neither a match nor a flag.
        $shorter = count($mine) <= count($theirs) ? $mine : $theirs;
        $longer = $shorter === $mine ? $theirs : $mine;
        if (array_diff($shorter, $longer) !== []) {
            return array_intersect($mine, $theirs) === [] ? self::DIFFERENT : self::UNKNOWN;
        }
        $mineStrengths = $this->strengths($incoming['drug_as_printed'] ?? null, $incoming['strengths'] ?? null);
        $theirStrengths = $this->strengths($existing->drug_as_printed, $existing->strengths);
        // Every component's strength must agree: 500 mg / 125 mg and 875 mg / 125 mg share 125 mg.
        sort($mineStrengths);
        sort($theirStrengths);
        if ($mineStrengths !== [] && $theirStrengths !== [] && $mineStrengths !== $theirStrengths) {
            return self::DIFFERENT;
        }

        return self::SAME;
    }

    /**
     * The release-formulation words a name carries ("extended release", "ER", "XL"): metformin and
     * metformin ER are not interchangeable.
     *
     * @return list<string>
     */
    private function qualifiers(mixed ...$sources): array
    {
        $found = [];
        foreach ($sources as $source) {
            foreach (is_array($source) ? $source : [$source] as $text) {
                foreach (preg_split('/[^a-z0-9]+/', strtolower(is_scalar($text) ? (string) $text : ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $found[] = match ($word) {
                        'extended', 'er', 'xr', 'xl', 'sr', 'cr', 'la', 'prolonged' => 'extended',
                        'delayed', 'dr', 'ec', 'enteric' => 'delayed',
                        'orally', 'odt', 'disintegrating' => 'disintegrating',
                        'chewable' => 'chewable',
                        default => null,
                    };
                }
            }
        }
        $found = array_values(array_unique(array_filter($found)));
        sort($found);

        return $found;
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
    private function drugWords(mixed ...$sources): array
    {
        $words = [];
        foreach ($sources as $source) {
            foreach (is_array($source) ? $source : [$source] as $text) {
                foreach ($this->words($text) as $word) {
                    if (preg_match('/^[a-z]{3,}$/', $word) === 1) {
                        $words[] = $word;
                    }
                }
            }
        }

        return array_values(array_unique($words));
    }

    /** @return list<string> strengths as "20mg", "2.5ml" */
    private function strengths(mixed ...$sources): array
    {
        $found = [];
        foreach ($sources as $source) {
            foreach (is_array($source) ? $source : [$source] as $text) {
                if (is_scalar($text) && preg_match_all('/(\d+(?:\.\d+)?)\s*(mg|mcg|ug|g|ml|unit|units|iu|%)/i', (string) $text, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $found[] = (string) (float) $match[1].strtolower($match[2]);
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }
}
