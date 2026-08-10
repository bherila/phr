<?php

namespace Tests\Unit\Services\PHR;

use App\Services\PHR\Import\MeritainEobFingerprint;
use PHPUnit\Framework\TestCase;

class MeritainEobFingerprintTest extends TestCase
{
    public function test_it_ignores_pdf_presentation_and_member_id_variations(): void
    {
        $fingerprint = new MeritainEobFingerprint;
        $claim = $this->claim();
        $regenerated = [
            ...$claim,
            'filename' => 'regenerated.pdf',
            'member_id' => 'SYNTHETIC-SUFFIX',
            'print_date' => '2030-01-10',
            'raw_text' => 'Updated administrator address and footer.',
        ];

        self::assertSame(
            $fingerprint->fromParsed($claim),
            $fingerprint->fromParsed($regenerated),
        );
    }

    public function test_it_retains_a_reprocessed_claim_with_changed_financial_data(): void
    {
        $fingerprint = new MeritainEobFingerprint;
        $claim = $this->claim();
        $reprocessed = $claim;
        $reprocessed['lines'][0]['plan_payment'] = '180.00';
        $reprocessed['total_plan_payment'] = '180.00';

        self::assertNotSame(
            $fingerprint->fromParsed($claim),
            $fingerprint->fromParsed($reprocessed),
        );
    }

    /** @return array<string, mixed> */
    private function claim(): array
    {
        return [
            'filename' => 'original.pdf',
            'claim_number' => 'SYNTH-CLAIM-001',
            'claim_type' => 'medical',
            'processed_date' => '2030-01-03',
            'print_date' => '2030-01-04',
            'provider_name' => 'Synthetic Example Clinic',
            'member_id' => 'SYNTHETIC',
            'total_charges' => '300.00',
            'total_provider_discount' => '100.00',
            'total_ineligible_amount' => null,
            'total_deductible_applied' => null,
            'total_copay_applied' => null,
            'total_carrier_payment' => null,
            'total_plan_payment' => '200.00',
            'total_patient_responsibility' => '0.00',
            'raw_text' => 'Original administrator address and footer.',
            'lines' => [[
                'line_number' => 1,
                'procedure_code' => '00000',
                'revenue_code' => null,
                'service_start' => '2030-01-02',
                'service_end' => '2030-01-02',
                'total_charges' => '300.00',
                'provider_discount' => '100.00',
                'ineligible_amount' => null,
                'deductible_applied' => null,
                'copay_applied' => null,
                'carrier_payment' => null,
                'plan_payment' => '200.00',
                'patient_responsibility' => '0.00',
            ]],
        ];
    }
}
