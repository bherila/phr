<?php

namespace App\Services\PHR\Import;

use DateTimeImmutable;
use RuntimeException;

class DeltaDentalEobCsvParser
{
    public const string PARSER_VERSION = 'delta-dental-csv-v1';

    /** @var list<string> */
    private const array HEADERS = [
        'Claim Number',
        'Date of Service',
        'Submission Date',
        'Member Name',
        'Dentist',
        'Phone',
        'Procedure',
        'Procedure Code',
        'Accepted Fee',
        'Claim Deductible',
        'Delta Dental Pays',
        'You Pay',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function parseDirectory(string $directory): array
    {
        $csvPaths = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.csv') ?: [];
        if (count($csvPaths) !== 1) {
            throw new RuntimeException('Delta Dental directory must contain exactly one CSV export.');
        }

        return $this->parse($csvPaths[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Delta Dental CSV is not readable: {$path}");
        }

        try {
            $headers = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($headers)) {
                throw new RuntimeException('Delta Dental CSV has no header row.');
            }
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            if ($headers !== self::HEADERS) {
                throw new RuntimeException('Delta Dental CSV headers do not match the supported export.');
            }

            $rows = [];
            $rowNumber = 1;
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $rowNumber++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new RuntimeException("Delta Dental CSV row {$rowNumber} has an unexpected column count.");
                }

                /** @var array<string, string> $row */
                $row = array_combine($headers, array_map(static fn ($value): string => trim((string) $value), $values));
                $rows[] = $this->normalizeRow($row, $rowNumber);
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw new RuntimeException('Delta Dental CSV has no claim rows.');
        }

        $claims = [];
        foreach ($rows as $row) {
            $claims[$row['claim_number']][] = $row;
        }

        $parsed = [];
        foreach ($claims as $claimNumber => $claimRows) {
            $claimNumber = (string) $claimNumber;
            foreach (['service_date', 'submission_date', 'member_name', 'dentist', 'phone'] as $key) {
                if (count(array_unique(array_column($claimRows, $key))) !== 1) {
                    throw new RuntimeException("Delta Dental claim {$claimNumber} has inconsistent {$key} values.");
                }
            }

            $first = $claimRows[0];
            $lines = [];
            foreach ($claimRows as $index => $row) {
                $lines[] = [
                    'line_number' => $index + 1,
                    'procedure_code' => $row['procedure_code'],
                    'revenue_code' => null,
                    'code_type' => 'CDT',
                    'description' => $row['procedure'],
                    'service_start' => $row['service_date'],
                    'service_end' => $row['service_date'],
                    'accepted_fee' => $row['accepted_fee'],
                    'total_charges' => null,
                    'provider_discount' => null,
                    'ineligible_amount' => null,
                    'notes_applied' => null,
                    'deductible_applied' => $row['claim_deductible'],
                    'copay_applied' => null,
                    'benefit_percent' => null,
                    'carrier_payment' => $row['delta_dental_pays'],
                    'plan_payment' => $row['delta_dental_pays'],
                    'patient_responsibility' => $row['you_pay'],
                    'parsed_data' => ['source_row' => $row['source_row']],
                    'raw_text' => $row['raw_text'],
                ];
            }

            $parsed[] = [
                'claim_number' => $claimNumber,
                'claim_type' => 'dental',
                'administrator' => 'Delta Dental',
                'carrier' => 'Delta Dental',
                'plan_name' => null,
                'group_number' => null,
                'member_id' => null,
                'participant_name' => $first['member_name'],
                'patient_name' => $first['member_name'],
                'provider_name' => $first['dentist'],
                'provider_phone' => $first['phone'],
                'payment_to' => $first['dentist'],
                'provider_tin' => null,
                'check_number' => null,
                'check_amount' => null,
                'submission_date' => $first['submission_date'],
                'print_date' => null,
                'processed_date' => null,
                'total_accepted_fee' => $this->sumMoney($claimRows, 'accepted_fee'),
                'total_charges' => null,
                'total_provider_discount' => null,
                'total_ineligible_amount' => null,
                'total_deductible_applied' => $this->sumMoney($claimRows, 'claim_deductible'),
                'total_copay_applied' => null,
                'total_benefit_percent' => null,
                'total_carrier_payment' => $this->sumMoney($claimRows, 'delta_dental_pays'),
                'total_plan_payment' => $this->sumMoney($claimRows, 'delta_dental_pays'),
                'total_patient_responsibility' => $this->sumMoney($claimRows, 'you_pay'),
                'service_date' => $first['service_date'],
                'lines' => $lines,
                'parsed_data' => [
                    'source_csv' => basename($path),
                    'submission_date' => $first['submission_date'],
                    'provider_phone' => $first['phone'],
                    'rows' => array_column($claimRows, 'source_row'),
                ],
            ];
        }

        usort($parsed, static fn (array $a, array $b): int => [$a['service_date'], $a['claim_number']] <=> [$b['service_date'], $b['claim_number']]);

        return $parsed;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, int $rowNumber): array
    {
        $claimNumber = $row['Claim Number'];
        if (preg_match('/^\d{8,20}$/', $claimNumber) !== 1) {
            throw new RuntimeException("Delta Dental CSV row {$rowNumber} has an invalid claim number.");
        }
        $procedureCode = strtoupper($row['Procedure Code']);
        if (preg_match('/^D\d{4}$/', $procedureCode) !== 1) {
            throw new RuntimeException("Delta Dental CSV row {$rowNumber} has an invalid CDT code.");
        }

        return [
            'claim_number' => $claimNumber,
            'service_date' => $this->date($row['Date of Service'], $rowNumber, 'service'),
            'submission_date' => $this->date($row['Submission Date'], $rowNumber, 'submission'),
            'member_name' => $this->required($row['Member Name'], $rowNumber, 'member name'),
            'dentist' => $this->required($row['Dentist'], $rowNumber, 'dentist'),
            'phone' => $this->required($row['Phone'], $rowNumber, 'phone'),
            'procedure' => $this->required($row['Procedure'], $rowNumber, 'procedure'),
            'procedure_code' => $procedureCode,
            'accepted_fee' => $this->money($row['Accepted Fee'], $rowNumber, 'accepted fee'),
            'claim_deductible' => $this->money($row['Claim Deductible'], $rowNumber, 'claim deductible'),
            'delta_dental_pays' => $this->money($row['Delta Dental Pays'], $rowNumber, 'Delta Dental pays'),
            'you_pay' => $this->money($row['You Pay'], $rowNumber, 'you pay'),
            'source_row' => $row,
            'raw_text' => implode(',', $row),
        ];
    }

    private function required(string $value, int $rowNumber, string $field): string
    {
        if ($value === '') {
            throw new RuntimeException("Delta Dental CSV row {$rowNumber} has no {$field}.");
        }

        return $value;
    }

    private function date(string $value, int $rowNumber, string $field): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException("Delta Dental CSV row {$rowNumber} has an invalid {$field} date.");
        }

        return $value;
    }

    private function money(string $value, int $rowNumber, string $field): string
    {
        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new RuntimeException("Delta Dental CSV row {$rowNumber} has an invalid {$field} amount.");
        }

        return number_format((float) $value, 2, '.', '');
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumMoney(array $rows, string $key): string
    {
        $cents = array_sum(array_map(static fn (array $row): int => (int) round(((float) $row[$key]) * 100), $rows));

        return number_format($cents / 100, 2, '.', '');
    }
}
