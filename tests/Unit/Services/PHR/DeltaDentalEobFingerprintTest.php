<?php

namespace Tests\Unit\Services\PHR;

use App\Services\PHR\Import\DeltaDentalEobFingerprint;
use PHPUnit\Framework\TestCase;

class DeltaDentalEobFingerprintTest extends TestCase
{
    public function test_it_changes_when_delta_specific_financial_data_changes(): void
    {
        $claim = [
            'claim_number' => '20300102000001',
            'submission_date' => '2030-01-03',
            'patient_name' => 'Synthetic Patient',
            'provider_name' => 'Example Dentist',
            'provider_phone' => '(555) 010-0200',
            'total_accepted_fee' => '100.00',
            'total_deductible_applied' => '0.00',
            'total_carrier_payment' => '100.00',
            'total_patient_responsibility' => '0.00',
            'lines' => [[
                'procedure_code' => 'D0120',
                'description' => 'Synthetic evaluation',
                'service_start' => '2030-01-02',
                'accepted_fee' => '100.00',
                'deductible_applied' => '0.00',
                'carrier_payment' => '100.00',
                'patient_responsibility' => '0.00',
            ]],
        ];

        $fingerprint = new DeltaDentalEobFingerprint;
        $before = $fingerprint->fromParsed($claim);
        $claim['lines'][0]['accepted_fee'] = '101.00';

        self::assertNotSame($before, $fingerprint->fromParsed($claim));
    }
}
