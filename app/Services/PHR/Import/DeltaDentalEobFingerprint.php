<?php

namespace App\Services\PHR\Import;

use JsonException;

class DeltaDentalEobFingerprint
{
    /**
     * @param  array<string, mixed>  $claim
     *
     * @throws JsonException
     */
    public function fromParsed(array $claim): string
    {
        $lines = array_map(static fn (array $line): array => [
            'procedure_code' => $line['procedure_code'],
            'description' => $line['description'],
            'service_start' => $line['service_start'],
            'accepted_fee' => $line['accepted_fee'],
            'deductible_applied' => $line['deductible_applied'],
            'carrier_payment' => $line['carrier_payment'],
            'patient_responsibility' => $line['patient_responsibility'],
        ], $claim['lines']);

        return hash('sha256', json_encode([
            'claim_number' => $claim['claim_number'],
            'submission_date' => $claim['submission_date'],
            'patient_name' => mb_strtoupper((string) $claim['patient_name']),
            'provider_name' => mb_strtoupper((string) $claim['provider_name']),
            'provider_phone' => $claim['provider_phone'],
            'total_accepted_fee' => $claim['total_accepted_fee'],
            'total_deductible_applied' => $claim['total_deductible_applied'],
            'total_carrier_payment' => $claim['total_carrier_payment'],
            'total_patient_responsibility' => $claim['total_patient_responsibility'],
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR));
    }
}
