<?php

namespace Tests\Unit\Services\PHR;

use App\Services\PHR\Import\DeltaDentalClaimAmounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeltaDentalClaimAmountsTest extends TestCase
{
    public function test_the_first_csv_row_is_derived_from_independent_totals_in_all_amount_columns(): void
    {
        $claim = self::claim();
        $result = (new DeltaDentalClaimAmounts)->reconcile($claim, self::pdf());
        self::assertSame(['55.00', '46.00', '77.00'], array_column($result['lines'], 'accepted_fee'));
        self::assertSame('178.00', $result['total_accepted_fee']);
        self::assertSame('10.00', $result['lines'][0]['deductible_applied']);
        self::assertSame('40.00', $result['lines'][0]['carrier_payment']);
        self::assertSame('40.00', $result['lines'][0]['plan_payment']);
        self::assertSame('15.00', $result['lines'][0]['patient_responsibility']);
        self::assertSame('30.00', $result['total_deductible_applied']);
        self::assertSame('140.00', $result['total_plan_payment']);
        self::assertSame('38.00', $result['total_patient_responsibility']);
        self::assertTrue($result['parsed_data']['amount_validation']['first_row_derived']);
        self::assertSame('178.00', $result['lines'][0]['parsed_data']['amount_derivation']['amounts']['accepted_fee']['csv_amount']);
        self::assertSame('55.00', $result['lines'][0]['parsed_data']['amount_derivation']['amounts']['accepted_fee']['derived_amount']);
    }

    public function test_true_line_amounts_are_validated_without_derivation(): void
    {
        $claim = self::claim();
        $claim['lines'][0] = [...$claim['lines'][0], 'accepted_fee' => '55.00', 'deductible_applied' => '10.00', 'carrier_payment' => '40.00', 'plan_payment' => '40.00', 'patient_responsibility' => '15.00'];
        $result = (new DeltaDentalClaimAmounts)->reconcile($claim, self::pdf());
        self::assertSame($claim['lines'], $result['lines']);
        self::assertFalse($result['parsed_data']['amount_validation']['first_row_derived']);
    }

    public function test_two_lines_and_zero_remaining_amounts_are_reconciled(): void
    {
        $claim = self::claim();
        $claim['lines'] = array_slice($claim['lines'], 0, 2);
        $result = (new DeltaDentalClaimAmounts)->reconcile($claim, self::pdf());
        self::assertSame('132.00', $result['lines'][0]['accepted_fee']);
        $claim['lines'][1] = array_fill_keys(array_keys($claim['lines'][1]), '0.00');
        $result = (new DeltaDentalClaimAmounts)->reconcile($claim, self::pdf());
        self::assertSame('178.00', $result['lines'][0]['accepted_fee']);
        self::assertFalse($result['parsed_data']['amount_validation']['first_row_derived']);
    }

    #[DataProvider('invalidEvidence')]
    public function test_missing_ambiguous_or_mismatching_totals_are_rejected(string $text, string $message): void
    {
        $this->expectExceptionMessage($message);
        (new DeltaDentalClaimAmounts)->reconcile(self::claim(), $text);
    }

    public static function invalidEvidence(): iterable
    {
        yield 'wrong claim' => [str_replace('20300102000001', '20300102000002', self::pdf()), 'Claim number'];
        yield 'no breakdown' => ['20300102000001', 'Cost Breakdown'];
        yield 'duplicate breakdown' => [self::pdf()."\nCost Breakdown\n", 'Cost Breakdown'];
        yield 'missing label' => [str_replace('You Pay: $38.00', '', self::pdf()), 'You Pay'];
        yield 'duplicate label' => [self::pdf()."\nAccepted Fee: $178.00", 'Accepted Fee'];
        yield 'arbitrary mismatch' => [str_replace('$178.00', '$179.00', self::pdf()), 'do not match'];
        yield 'remaining lines exceed total' => [str_replace('$178.00', '$100.00', self::pdf()), 'do not match'];
    }

    public function test_negative_remainder_is_rejected_instead_of_storing_a_negative_first_line(): void
    {
        $claim = self::claim();
        $claim['lines'][1]['accepted_fee'] = '179.00';
        $this->expectExceptionMessage('remaining lines exceed');
        (new DeltaDentalClaimAmounts)->reconcile($claim, self::pdf());
    }

    /** @return array<string, mixed> */
    public static function claim(): array
    {
        $lines = [];
        foreach ([['178.00', '30.00', '140.00', '38.00'], ['46.00', '5.00', '40.00', '6.00'], ['77.00', '15.00', '60.00', '17.00']] as $amounts) {
            $lines[] = ['accepted_fee' => $amounts[0], 'deductible_applied' => $amounts[1], 'carrier_payment' => $amounts[2], 'plan_payment' => $amounts[2], 'patient_responsibility' => $amounts[3], 'parsed_data' => []];
        }

        return ['claim_number' => '20300102000001', 'lines' => $lines, 'parsed_data' => ['source_csv' => 'claims.csv']];
    }

    public static function pdf(): string
    {
        return <<<'TEXT'
Claim Number: 20300102000001
Cost Breakdown
Accepted Fee: $178.00
Claim Deductible: $30.00
Delta Dental Pays: $140.00
You Pay: $38.00
TEXT;
    }
}
