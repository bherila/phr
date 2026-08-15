<?php

namespace Tests\Unit\Services\PHR;

use App\Services\PHR\Import\DeltaDentalEobCsvParser;
use PHPUnit\Framework\TestCase;

class DeltaDentalEobCsvParserTest extends TestCase
{
    public function test_it_groups_claim_lines_and_preserves_delta_specific_fields(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'delta-eob-');
        self::assertIsString($path);
        file_put_contents($path, <<<'CSV'
Claim Number,Date of Service,Submission Date,Member Name,Dentist,Phone,Procedure,Procedure Code,Accepted Fee,Claim Deductible,Delta Dental Pays,You Pay
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Periodic oral evaluation established patient,D0120,100.00,0.00,80.00,20.00
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Prophylaxis (cleaning) adult,D1110,75.00,5.00,70.00,0.00
CSV);

        try {
            $claims = (new DeltaDentalEobCsvParser)->parse($path);
        } finally {
            unlink($path);
        }

        self::assertCount(1, $claims);
        $claim = $claims[0];
        self::assertSame('20300102000001', $claim['claim_number']);
        self::assertSame('2030-01-03', $claim['submission_date']);
        self::assertSame('(555) 010-0200', $claim['provider_phone']);
        self::assertSame('175.00', $claim['total_accepted_fee']);
        self::assertSame('5.00', $claim['total_deductible_applied']);
        self::assertSame('150.00', $claim['total_carrier_payment']);
        self::assertSame('20.00', $claim['total_patient_responsibility']);
        self::assertSame('CDT', $claim['lines'][0]['code_type']);
        self::assertSame('D1110', $claim['lines'][1]['procedure_code']);
        self::assertSame('75.00', $claim['lines'][1]['accepted_fee']);
    }

    public function test_it_rejects_inconsistent_claim_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'delta-eob-');
        self::assertIsString($path);
        file_put_contents($path, <<<'CSV'
Claim Number,Date of Service,Submission Date,Member Name,Dentist,Phone,Procedure,Procedure Code,Accepted Fee,Claim Deductible,Delta Dental Pays,You Pay
20300102000001,2030-01-02,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Periodic oral evaluation established patient,D0120,100.00,0.00,100.00,0.00
20300102000001,2030-01-04,2030-01-03,Synthetic Patient,EXAMPLE DENTIST,(555) 010-0200,Prophylaxis (cleaning) adult,D1110,75.00,0.00,75.00,0.00
CSV);

        try {
            $this->expectExceptionMessage('inconsistent service_date');
            (new DeltaDentalEobCsvParser)->parse($path);
        } finally {
            unlink($path);
        }
    }
}
