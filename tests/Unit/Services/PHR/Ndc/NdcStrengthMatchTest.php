<?php

namespace Tests\Unit\Services\PHR\Ndc;

use App\Services\PHR\Ndc\NdcLookupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NdcStrengthMatchTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function strengths(): array
    {
        return [
            'a decimal point is not dropped: 2.5 is not 25' => ['2.5 mg', '25 mg/1', false],
            'and 25 is not 2.5' => ['25 mg', '2.5 mg/1', false],
            '0.5 mg/mL is not 5 mg/mL' => ['0.5 mg/mL', '5 mg/mL', false],
            '5 mg/mL is not 0.5 mg/mL' => ['5 mg/mL', '0.5 mg/mL', false],
            '100 mg is not 10 mg' => ['100 mg', '10 mg/1', false],
            'the same number is compared numerically' => ['2.5 mg', '2.50 mg/1', true],
            'a leading zero does not matter' => ['02.5 mg', '2.5 mg/1', true],
            'per-unit strengths match themselves' => ['0.5 mg/mL', '0.5 mg/ml', true],
            'a typed strength without a denominator accepts any listed one' => ['5 mg', '5 mg/mL', true],
            'a typed denominator must match' => ['5 mg/mL', '5 mg/1', false],
            'units are standardised' => ['10 MG', '10 mg/1', true],
            'mcg is ug' => ['10 mcg', '10 ug/1', true],
            'a different unit is a different strength' => ['10 mg', '10 g/1', false],
            'typed without a space' => ['12.5mg', '12.5 mg/1', true],
            'not a strength at all' => ['tablet', '10 mg/1', false],
        ];
    }

    #[DataProvider('strengths')]
    public function test_strengths_compare_as_numbers_with_standard_units(string $wanted, string $listed, bool $expected): void
    {
        $this->assertSame($expected, NdcLookupService::strengthsMatch($wanted, $listed));
    }
}
