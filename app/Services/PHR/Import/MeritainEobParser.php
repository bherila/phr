<?php

namespace App\Services\PHR\Import;

use Illuminate\Support\Carbon;

/**
 * Parser for the text layer emitted by Meritain EOB PDFs.
 *
 * The source PDFs use fixed-width tables, but there are several page-width
 * variants. We therefore derive the financial column positions from the table
 * headings and retain the original line plus token positions in parsed_data.
 */
class MeritainEobParser
{
    public const string PARSER_VERSION = 'meritain-layout-v1';

    /**
     * @return array<string, mixed>
     */
    public function parse(string $text, string $filename = ''): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        if ($this->isModernLayout($lines)) {
            return $this->parseModernLayout($text, $lines, $filename);
        }

        $columns = $this->columnMap($lines);
        $rows = [];
        $lineNumber = 0;
        $totalRow = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Za-z0-9]{3,8})(?:\s*\/\s*([0-9]{4}))?\s+(\d{2}-\d{2}-\d{2})(?:\s+(\d{2}-\d{2}-\d{2}))?\b/', $line, $match) !== 1) {
                if (preg_match('/^\s*TOTALS\b/', $line) === 1) {
                    $totalRow = $this->financialColumns($line, $columns);
                }

                continue;
            }

            $lineNumber++;
            $serviceStart = $this->date($match[3]);
            $serviceEnd = isset($match[4]) ? $this->date($match[4]) : $serviceStart;
            $notes = $this->notes($line, $columns);

            $rows[] = [
                'line_number' => $lineNumber,
                'procedure_code' => strtoupper($match[1]),
                'revenue_code' => $this->optionalMatch($match, 2),
                'code_type' => $this->codeType($match[1]),
                'description' => null,
                'service_start' => $serviceStart,
                'service_end' => $serviceEnd,
                ...$this->financialColumns($line, $columns),
                'notes_applied' => $notes,
                'raw_text' => rtrim($line),
                'parsed_data' => [
                    'date_tokens' => array_filter([$match[3], $this->optionalMatch($match, 4)]),
                    'revenue_code' => $this->optionalMatch($match, 2),
                    'tokens' => $this->tokens($line),
                    'columns' => $columns,
                ],
            ];
        }

        $provider = $this->provider($lines, $columns);
        $paymentTo = $this->paymentTo($lines, $columns);
        $claimNumber = $this->matchValue($text, '/Claim No:\s*([A-Z0-9-]+)/i');
        $processedDate = $this->date($this->matchValue($text, '/Processed On:\s*(\d{2}-\d{2}-\d{2})/i'));
        $printDate = $this->date($this->matchValue($text, '/Print Date:\s*(\d{2}-\d{2}-\d{2})/i'));
        $memberId = $this->matchValue($text, '/ID No:\s*([^\s]+)/i');
        $groupNumber = $this->matchValue($text, '/Group No:\s*([^\s]+)/i');
        $participant = $this->lineValue($lines, 'Participant:');
        $patient = $this->lineValue($lines, 'Patient:');
        $planName = $this->lineValue($lines, 'Group Name:');
        $providerTin = $this->matchValue($text, '/TIN:\s*([0-9-]+)/i');
        $check = $this->check($text);
        $isPharmacy = str_contains(strtoupper($text), 'CAREMARK');

        return [
            'filename' => $filename !== '' ? $filename : null,
            'claim_number' => $claimNumber,
            'claim_type' => $isPharmacy ? 'pharmacy' : 'medical',
            'administrator' => 'Meritain Health',
            'carrier' => 'Meritain Health',
            'plan_name' => $planName,
            'group_number' => $groupNumber,
            'member_id' => $memberId,
            'participant_name' => $participant,
            'patient_name' => $patient,
            'provider_name' => $provider,
            'payment_to' => $paymentTo,
            'provider_tin' => $providerTin,
            'check_number' => $check['number'],
            'check_amount' => $check['amount'],
            'print_date' => $printDate,
            'processed_date' => $processedDate,
            'total_charges' => $totalRow['total_charges'] ?? $this->sum($rows, 'total_charges'),
            'total_provider_discount' => $totalRow['provider_discount'] ?? $this->sum($rows, 'provider_discount'),
            'total_ineligible_amount' => $totalRow['ineligible_amount'] ?? $this->sum($rows, 'ineligible_amount'),
            'total_deductible_applied' => $totalRow['deductible_applied'] ?? $this->sum($rows, 'deductible_applied'),
            'total_copay_applied' => $totalRow['copay_applied'] ?? $this->sum($rows, 'copay_applied'),
            'total_benefit_percent' => $totalRow['benefit_percent'] ?? null,
            'total_carrier_payment' => $totalRow['carrier_payment'] ?? $this->sum($rows, 'carrier_payment'),
            'total_plan_payment' => $totalRow['plan_payment'] ?? $this->sum($rows, 'plan_payment'),
            'total_patient_responsibility' => $totalRow['patient_responsibility'] ?? $this->sum($rows, 'patient_responsibility'),
            'lines' => $rows,
            'raw_text' => $text,
            'parsed_data' => [
                'columns' => $columns,
                'parser_version' => self::PARSER_VERSION,
                'source_filename' => $filename !== '' ? $filename : null,
            ],
        ];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, mixed>
     */
    private function parseModernLayout(string $text, array $lines, string $filename): array
    {
        $columns = $this->modernColumnMap($lines);
        $rows = [];
        $lineNumber = 0;
        $totalRow = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d{2}\/\d{2}\/\d{2}(?:-\d{2}\/\d{2}\/\d{2})?)\s+([A-Za-z0-9]{4,8})\s*\/\s*([0-9]{4})?/', $line, $match) !== 1) {
                if (str_contains($line, 'Column Totals')) {
                    $totalRow = $this->modernFinancialColumns($line, $columns);
                }

                continue;
            }

            $lineNumber++;
            $dates = explode('-', $match[1]);
            $serviceStart = $this->date($dates[0]);
            $serviceEnd = $this->date($dates[1] ?? $dates[0]);
            $description = $this->modernDescription($line, $match[0]);
            $rows[] = [
                'line_number' => $lineNumber,
                'procedure_code' => strtoupper($match[2]),
                'revenue_code' => $this->optionalMatch($match, 3),
                'code_type' => $this->codeType($match[2]),
                'description' => $description,
                'service_start' => $serviceStart,
                'service_end' => $serviceEnd,
                ...$this->modernFinancialColumns($line, $columns),
                'notes_applied' => $this->modernNotes($line, $columns),
                'raw_text' => rtrim($line),
                'parsed_data' => [
                    'date_token' => $match[1],
                    'revenue_code' => $this->optionalMatch($match, 3),
                    'description' => $description,
                    'tokens' => $this->tokens($line),
                    'columns' => $columns,
                ],
            ];
        }

        $claimNumber = $this->matchValue($text, '/Claim #:\s*([A-Z0-9-]+)/i');
        $preparedDate = $this->modernDate($this->matchValue($text, '/Prepared On:\s*(\d{2}\/\d{2}\/\d{4})/i'));
        $provider = $this->matchValue($text, '/Provider:\s*([^\n]+?)(?:\s+Patient:|\s*$)/i');
        $patient = $this->lineValue($lines, 'Patient:');
        $planName = $this->lineValue($lines, 'Group Name:');
        $groupNumber = $this->matchValue($text, '/Group #:\s*([^\s]+)/i');
        $memberId = $this->matchValue($text, '/(?:Insured #|Patient #):\s*([^\s]+)/i');
        $check = $this->modernCheck($text);

        return [
            'filename' => $filename !== '' ? $filename : null,
            'claim_number' => $claimNumber,
            'claim_type' => 'medical',
            'administrator' => 'Meritain Health',
            'carrier' => 'Meritain Health',
            'plan_name' => $planName,
            'group_number' => $groupNumber,
            'member_id' => $memberId,
            'participant_name' => $this->lineValue($lines, 'Insured:'),
            'patient_name' => $patient,
            'provider_name' => $provider !== null ? trim($provider) : null,
            'payment_to' => $this->modernPaymentTo($lines),
            'provider_tin' => null,
            'check_number' => $check['number'],
            'check_amount' => $check['amount'],
            'print_date' => $preparedDate,
            'processed_date' => $preparedDate,
            'total_charges' => $totalRow['total_charges'] ?? $this->sum($rows, 'total_charges'),
            'total_provider_discount' => $totalRow['provider_discount'] ?? $this->sum($rows, 'provider_discount'),
            'total_ineligible_amount' => $totalRow['ineligible_amount'] ?? $this->sum($rows, 'ineligible_amount'),
            'total_deductible_applied' => $totalRow['deductible_applied'] ?? $this->sum($rows, 'deductible_applied'),
            'total_copay_applied' => $totalRow['copay_applied'] ?? $this->sum($rows, 'copay_applied'),
            'total_benefit_percent' => null,
            'total_carrier_payment' => $totalRow['carrier_payment'] ?? $this->sum($rows, 'carrier_payment'),
            'total_plan_payment' => $totalRow['plan_payment'] ?? $this->sum($rows, 'plan_payment'),
            'total_patient_responsibility' => $totalRow['patient_responsibility'] ?? $this->sum($rows, 'patient_responsibility'),
            'lines' => $rows,
            'raw_text' => $text,
            'parsed_data' => [
                'layout' => 'modern',
                'columns' => $columns,
                'parser_version' => self::PARSER_VERSION,
                'source_filename' => $filename !== '' ? $filename : null,
            ],
        ];
    }

    /** @param array<int, string> $lines */
    private function isModernLayout(array $lines): bool
    {
        $text = implode("\n", $lines);

        return str_contains($text, 'Claim #:') && str_contains($text, 'Treatment Service/');
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, int>
     */
    private function modernColumnMap(array $lines): array
    {
        $header = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'Dates') && str_contains($line, 'Rev Code') && str_contains($line, 'Responsible')) {
                $header = $line;
                break;
            }
        }
        if ($header === null) {
            return [
                'total_charges' => 57,
                'provider_discount' => 69,
                'ineligible_amount' => 84,
                'deductible_applied' => 95,
                'copay_applied' => 106,
                'carrier_payment' => 118,
                'plan_payment' => 129,
                'benefit_percent' => 135,
                'patient_responsibility' => 160,
            ];
        }

        preg_match_all('/Amount/', $header, $matches, PREG_OFFSET_CAPTURE);
        $amounts = array_map(static fn (array $match): int => (int) $match[1], $matches[0]);

        return [
            'total_charges' => $amounts[0] ?? 57,
            'provider_discount' => strpos($header, 'Discount') ?: 69,
            'ineligible_amount' => $amounts[1] ?? 84,
            'deductible_applied' => strpos($header, 'Deductible') ?: 95,
            'copay_applied' => strpos($header, 'CoPay') ?: 106,
            'carrier_payment' => strpos($header, 'Payment') ?: 118,
            'plan_payment' => $amounts[2] ?? 129,
            'benefit_percent' => strpos($header, 'At') ?: 135,
            'patient_responsibility' => strpos($header, 'Responsible') ?: 160,
        ];
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<string, string|null>
     */
    private function modernFinancialColumns(string $line, array $columns): array
    {
        if (str_contains($line, 'Column Totals')) {
            $money = $this->moneyValues($line);

            return [
                'total_charges' => $money[0] ?? null,
                'provider_discount' => $money[1] ?? null,
                'ineligible_amount' => $money[2] ?? null,
                'deductible_applied' => $money[3] ?? null,
                'copay_applied' => $money[4] ?? null,
                'carrier_payment' => $money[5] ?? null,
                'plan_payment' => $money[6] ?? null,
                'benefit_percent' => null,
                'patient_responsibility' => $money[7] ?? null,
            ];
        }

        $fields = array_keys($columns);
        $values = [];
        foreach ($fields as $index => $field) {
            if ($field === 'benefit_percent') {
                $values[$field] = $this->benefitBetween($line, $columns['plan_payment'], $columns['patient_responsibility']);

                continue;
            }

            $start = $columns[$field];
            $end = $index + 1 < count($fields) ? $columns[$fields[$index + 1]] : strlen($line) + 1;
            $values[$field] = $this->moneyBetween($line, $start, $end);
        }

        return $values;
    }

    /** @return array<int, string> */
    private function moneyValues(string $line): array
    {
        preg_match_all('/-?\$?[\d,]+\.\d{2}/', $line, $matches);

        return array_map(static fn (string $value): string => str_replace(['$', ','], '', $value), $matches[0]);
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<int, string>
     */
    private function modernNotes(string $line, array $columns): array
    {
        $start = $columns['plan_payment'];
        $end = strlen($line);
        preg_match_all('/(?<![A-Za-z])[a-z](?![A-Za-z])/', substr($line, max(0, $start), max(0, $end - $start)), $matches);

        return array_values(array_unique($matches[0]));
    }

    private function benefitBetween(string $line, int $start, int $end): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)%/', substr($line, max(0, $start), max(0, $end - $start)), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return array{number: string|null, amount: string|null} */
    private function modernCheck(string $text): array
    {
        if (preg_match('/\b([0-9]{5,})\s+\$?([\d,]+\.\d{2})\b/', $text, $matches) !== 1) {
            return ['number' => null, 'amount' => null];
        }

        return [
            'number' => trim($matches[1]),
            'amount' => str_replace(',', '', $matches[2]),
        ];
    }

    /** @param array<int, string> $lines */
    private function modernPaymentTo(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/\b\d{5,}\s+\$?[\d,]+\.\d{2}\s*$/', trim($line)) !== 1) {
                continue;
            }
            if (preg_match('/\b\d{4}\s+(.+?)\s+\d{5,}\s+\$?[\d,]+\.\d{2}\s*$/', trim($line), $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function modernDescription(string $line, string $matchedPrefix): ?string
    {
        $offset = strlen($matchedPrefix);
        if (preg_match('/-?\$?[\d,]+\.\d{2}/', $line, $matches, PREG_OFFSET_CAPTURE, $offset) !== 1) {
            return null;
        }

        $description = trim(substr($line, $offset, (int) $matches[0][1] - $offset));

        return $description !== '' ? $description : null;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, int>
     */
    private function columnMap(array $lines): array
    {
        $header = null;
        $headerLabels = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'CHARGES') && str_contains($line, 'DISCOUNT')) {
                $header = $line;
            }
            if (str_contains($line, 'DATES OF SERVICE') && str_contains($line, 'BEN.')) {
                $headerLabels = $line;
            }
        }

        $fallback = [
            'total_charges' => 42,
            'provider_discount' => 57,
            'ineligible_amount' => 66,
            'deductible_applied' => 83,
            'copay_applied' => 111,
            'benefit_percent' => 126,
            'carrier_payment' => 138,
            'plan_payment' => 147,
            'patient_responsibility' => 161,
        ];

        if ($header === null) {
            return $fallback;
        }

        $positions = [];
        foreach (array_keys($fallback) as $field) {
            $label = match ($field) {
                'total_charges' => 'CHARGES',
                'provider_discount' => 'DISCOUNT',
                'ineligible_amount' => 'AMOUNT',
                'deductible_applied' => 'TO DED.',
                'copay_applied' => 'TO COPAY',
                'benefit_percent' => 'BEN.',
                'carrier_payment' => 'PYMT',
                'plan_payment' => 'PLAN',
                'patient_responsibility' => 'SIBILITY',
            };
            $position = strpos($header, $label);
            if ($field === 'benefit_percent' && $headerLabels !== null) {
                $position = strpos($headerLabels, 'BEN.');
            }
            if ($position !== false) {
                $positions[$field] = $position;
            }
        }

        return count($positions) === count($fallback) ? $positions : $fallback;
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<string, string|null>
     */
    private function financialColumns(string $line, array $columns): array
    {
        $fieldOrder = array_keys($columns);
        $values = [];
        foreach ($fieldOrder as $index => $field) {
            $start = $columns[$field];
            $end = $index + 1 < count($fieldOrder) ? $columns[$fieldOrder[$index + 1]] : strlen($line) + 1;
            $values[$field] = $this->moneyBetween($line, $start, $end);
        }

        return $values;
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<int, string>
     */
    private function notes(string $line, array $columns): array
    {
        $start = $columns['ineligible_amount'] - 2;
        $end = $columns['benefit_percent'];
        preg_match_all('/(?<![A-Za-z])[a-z](?![A-Za-z])/', substr($line, max(0, $start), max(0, $end - $start)), $matches);

        return array_values(array_unique($matches[0]));
    }

    /** @return array<int, array{value: string, start: int, end: int}> */
    private function tokens(string $line): array
    {
        preg_match_all('/\S+/', $line, $matches, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $match): array => [
            'value' => (string) $match[0],
            'start' => (int) $match[1],
            'end' => (int) $match[1] + strlen((string) $match[0]),
        ], $matches[0]);
    }

    private function moneyBetween(string $line, int $start, int $end): ?string
    {
        $candidates = [];
        preg_match_all('/-?\$?[\d,]+\.\d{2}/', $line, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $match) {
            $position = (int) $match[1];
            if ($position >= $start - 3 && $position < $end + 3) {
                $candidates[] = [
                    'value' => str_replace(['$', ','], '', (string) $match[0]),
                    'distance' => abs($position - $start),
                ];
            }
        }

        usort($candidates, static fn (array $left, array $right): int => $left['distance'] <=> $right['distance']);

        return $candidates[0]['value'] ?? null;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sum(array $rows, string $field): ?string
    {
        $sum = 0.0;
        $found = false;
        foreach ($rows as $row) {
            if (! is_string($row[$field] ?? null)) {
                continue;
            }
            $sum += (float) $row[$field];
            $found = true;
        }

        return $found ? number_format($sum, 2, '.', '') : null;
    }

    private function codeType(string $code): string
    {
        return match (true) {
            preg_match('/^\d{5}$/', $code) === 1 => 'cpt_hcpcs',
            preg_match('/^\d{4}[A-Z]$/i', $code) === 1 => 'hcpcs',
            preg_match('/^94[A-Z]$/i', $code) === 1 => 'pharmacy_plan',
            default => 'unknown',
        };
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, int>  $columns
     */
    private function provider(array $lines, array $columns): ?string
    {
        foreach ($lines as $index => $line) {
            if (! str_contains($line, 'Plan For Services Provided By')) {
                continue;
            }
            for ($offset = 1; $offset <= 5; $offset++) {
                $candidateLine = $lines[$index + $offset] ?? '';
                $candidate = trim(substr($candidateLine, 0, max(40, $columns['total_charges'])));
                $candidate = preg_replace('/\s{2,}.*$/', '', $candidate) ?: $candidate;
                if ($this->isProviderCandidate($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, int>  $columns
     */
    private function paymentTo(array $lines, array $columns): ?string
    {
        foreach ($lines as $index => $line) {
            if (! str_contains($line, 'Payment To:')) {
                continue;
            }
            for ($offset = 0; $offset <= 3; $offset++) {
                $candidateLine = $lines[$index + $offset] ?? '';
                $candidate = $offset === 0
                    ? trim(substr($candidateLine, strpos($candidateLine, 'Payment To:') + strlen('Payment To:')))
                    : trim(substr($candidateLine, max(0, $columns['plan_payment'])));
                $candidate = preg_replace('/\s{2,}.*$/', '', $candidate) ?: $candidate;
                if ($this->isProviderCandidate($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function isProviderCandidate(string $candidate): bool
    {
        if ($candidate === '' || str_contains($candidate, ':') || preg_match('/\d/', $candidate) === 1) {
            return false;
        }

        if (preg_match('/^[A-Z0-9 .,&\'()\/-]+$/', $candidate) !== 1) {
            return false;
        }

        return ! preg_match('/^(PO BOX|TIN|ADDRESS|GROUP|PROCESSED|CLAIM|PARTICIPANT|PATIENT)/', $candidate);
    }

    /** @param array<int, string> $lines */
    private function lineValue(array $lines, string $label): ?string
    {
        foreach ($lines as $line) {
            $position = strpos($line, $label);
            if ($position === false) {
                continue;
            }
            $value = trim(substr($line, $position + strlen($label)));
            $value = preg_replace('/\s{2,}(?:ID No:|Address:|Patient #:|Patient:|Group Name:|Group No:|Group #:|Processed On:).*$/', '', $value) ?: $value;
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<int, string> $match */
    private function optionalMatch(array $match, int $index): ?string
    {
        $value = $match[$index] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function matchValue(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $matches) === 1 ? trim($matches[1]) : null;
    }

    /** @return array{number: string|null, amount: string|null} */
    private function check(string $text): array
    {
        if (preg_match('/Check #\s*([A-Z0-9-]+)\s+Amount\s+\$?([\d,]+\.\d{2})/i', $text, $matches) !== 1) {
            return ['number' => null, 'amount' => null];
        }

        return [
            'number' => trim($matches[1]),
            'amount' => str_replace(',', '', $matches[2]),
        ];
    }

    private function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            return $this->modernDate($value);
        }

        try {
            return Carbon::createFromFormat('!m-d-y', trim($value))->toDateString();
        } catch (\Throwable) {
            return $this->modernDate($value);
        }
    }

    private function modernDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $formats = preg_match('/\/\d{2}$/', trim($value)) === 1
            ? ['!m/d/y', '!m/d/Y']
            : ['!m/d/Y', '!m/d/y'];
        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, trim($value))->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
