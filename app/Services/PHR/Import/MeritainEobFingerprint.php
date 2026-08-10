<?php

namespace App\Services\PHR\Import;

use DateTimeInterface;
use JsonException;

class MeritainEobFingerprint
{
    /**
     * Build a stable identity for the claim itself, independent of PDF bytes,
     * print-time boilerplate, and harmless subscriber-identifier formatting.
     *
     * @param  array<string, mixed>  $claim
     *
     * @throws JsonException
     */
    public function fromParsed(array $claim): string
    {
        $lines = array_map(fn (array $line): array => [
            'procedure_code' => $this->text($line['procedure_code'] ?? null),
            'revenue_code' => $this->text($line['revenue_code'] ?? null),
            'service_start' => $this->date($line['service_start'] ?? null),
            'service_end' => $this->date($line['service_end'] ?? null),
            'total_charges' => $this->money($line['total_charges'] ?? null),
            'provider_discount' => $this->money($line['provider_discount'] ?? null),
            'ineligible_amount' => $this->money($line['ineligible_amount'] ?? null),
            'deductible_applied' => $this->money($line['deductible_applied'] ?? null),
            'copay_applied' => $this->money($line['copay_applied'] ?? null),
            'carrier_payment' => $this->money($line['carrier_payment'] ?? null),
            'plan_payment' => $this->money($line['plan_payment'] ?? null),
            'patient_responsibility' => $this->money($line['patient_responsibility'] ?? null),
        ], $claim['lines'] ?? []);

        $payload = [
            'claim_number' => $this->text($claim['claim_number'] ?? null),
            'claim_type' => $this->text($claim['claim_type'] ?? null),
            'processed_date' => $this->date($claim['processed_date'] ?? null),
            'provider_name' => $this->text($claim['provider_name'] ?? null),
            'total_charges' => $this->money($claim['total_charges'] ?? null),
            'total_provider_discount' => $this->money($claim['total_provider_discount'] ?? null),
            'total_ineligible_amount' => $this->money($claim['total_ineligible_amount'] ?? null),
            'total_deductible_applied' => $this->money($claim['total_deductible_applied'] ?? null),
            'total_copay_applied' => $this->money($claim['total_copay_applied'] ?? null),
            'total_carrier_payment' => $this->money($claim['total_carrier_payment'] ?? null),
            'total_plan_payment' => $this->money($claim['total_plan_payment'] ?? null),
            'total_patient_responsibility' => $this->money($claim['total_patient_responsibility'] ?? null),
            'lines' => $lines,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return number_format((float) str_replace([',', '$'], '', (string) $value), 2, '.', '');
    }
}
