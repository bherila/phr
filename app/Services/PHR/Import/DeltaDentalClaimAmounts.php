<?php

namespace App\Services\PHR\Import;

use RuntimeException;

/** Reconcile CSV procedure amounts only against independently stated PDF totals. */
final class DeltaDentalClaimAmounts
{
    /** PDF cost-breakdown label => CSV line column. */
    private const array COLUMNS = [
        'Accepted Fee' => 'accepted_fee',
        'Claim Deductible' => 'deductible_applied',
        'Delta Dental Pays' => 'carrier_payment',
        'You Pay' => 'patient_responsibility',
    ];

    /**
     * Fail closed for an unfamiliar/ambiguous PDF layout. A claim number alone
     * is not evidence that the CSV's amounts are procedure amounts.
     *
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    public function reconcile(array $claim, string $pdfText): array
    {
        if (preg_match('/\b'.preg_quote((string) $claim['claim_number'], '/').'\b/', $pdfText) !== 1) {
            throw new RuntimeException('Claim number was not found in the authoritative claim PDF.');
        }
        $sections = preg_split('/^\h*Cost\h+Breakdown\h*:?\h*$/im', $pdfText);
        if ($sections === false || count($sections) !== 2) {
            throw new RuntimeException('Independent claim totals could not be read: expected one Cost Breakdown section. Read the EOB and use eobs.upsert or eobs.update.');
        }
        $totals = [];
        foreach (self::COLUMNS as $label => $column) {
            // Only an explicit, standalone summary label/value pair is supported.
            // Never grab a matching amount from the procedure table elsewhere.
            preg_match_all('/^\h*'.preg_quote($label, '/').'\h*:?\h*\$?([0-9]+(?:,[0-9]{3})*\.[0-9]{2})\h*$/im', $sections[1], $matches);
            if (count($matches[1]) !== 1) {
                throw new RuntimeException("Independent claim total for {$label} is missing or ambiguous.");
            }
            $totals[$column] = $this->cents(str_replace(',', '', $matches[1][0]));
        }

        $lines = $claim['lines'];
        if ($lines === []) {
            throw new RuntimeException('The CSV claim has no procedure lines.');
        }
        $sums = [];
        foreach (self::COLUMNS as $column) {
            $sums[$column] = array_sum(array_map(fn (array $line): int => $this->cents((string) $line[$column]), $lines));
        }
        $matchesLines = $sums === $totals;
        $firstIsTotal = true;
        foreach ($totals as $column => $total) {
            $firstIsTotal = $firstIsTotal && $this->cents((string) $lines[0][$column]) === $total;
        }
        if (! $matchesLines && ! $firstIsTotal) {
            throw new RuntimeException('CSV line amounts do not match the independent EOB claim totals; no amounts were imported.');
        }
        $derived = [];
        if (! $matchesLines) {
            foreach ($totals as $column => $total) {
                $remainder = $total - ($sums[$column] - $this->cents((string) $lines[0][$column]));
                if ($remainder < 0) {
                    throw new RuntimeException('CSV remaining lines exceed the independent EOB claim total.');
                }
                $derived[$column] = ['csv_amount' => $lines[0][$column], 'derived_amount' => $this->money($remainder)];
                $lines[0][$column] = $this->money($remainder);
            }
            $lines[0]['plan_payment'] = $lines[0]['carrier_payment'];
            $lines[0]['parsed_data']['amount_derivation'] = [
                'method' => 'independent_pdf_total_minus_remaining_csv_lines', 'amounts' => $derived,
            ];
        }
        foreach ($totals as $column => $total) {
            if (array_sum(array_map(fn (array $line): int => $this->cents((string) $line[$column]), $lines)) !== $total) {
                throw new RuntimeException('Reconciled CSV lines do not match the independent EOB claim totals.');
            }
            $claim['total_'.$column] = $this->money($total);
        }
        $claim['total_plan_payment'] = $claim['total_carrier_payment'];
        $claim['lines'] = $lines;
        $claim['parsed_data']['amount_validation'] = [
            'source' => 'authoritative_claim_pdf',
            'pdf_text_sha256' => hash('sha256', $pdfText),
            'totals' => array_map($this->money(...), $totals),
            'first_row_derived' => $derived !== [],
        ];

        return $claim;
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
