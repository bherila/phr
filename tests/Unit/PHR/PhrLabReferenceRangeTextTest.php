<?php

namespace Tests\Unit\PHR;

use App\Support\PHR\PhrLabReferenceRangeText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhrLabReferenceRangeTextTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: string|null}> */
    public static function cases(): iterable
    {
        yield 'NEG twice' => ['NEGNEG', 'NEG'];
        yield 'two words twice' => ['Not DetectedNot Detected', 'Not Detected'];
        yield 'unit twice' => ['mIU/mLmIU/mL', 'mIU/mL'];
        yield 'a number' => ['11', null];
        yield 'a year-like number' => ['2020', null];
        yield 'halves differ by case' => ['Not DetectedNot detected', null];
        yield 'four copies' => ['NEGNEGNEGNEG', null];
        yield 'separated' => ['NEG NEG', null];
        yield 'whitespace only' => ['  ', null];
        yield 'ordinary range' => ['13.6 - 17.5', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    /** @return iterable<string, array{0: mixed, 1: mixed, 2: mixed}> */
    public static function unitCases(): iterable
    {
        yield 'unit three times' => ['4.3 - 5.6 PERCENTPERCENTPERCENT', 'PERCENT', '4.3 - 5.6 PERCENT'];
        yield 'unit twice' => ['<165 IU/mLIU/mL', 'IU/mL', '<165 IU/mL'];
        yield 'unit once' => ['<0.1 x10E9/L', 'x10E9/L', '<0.1 x10E9/L'];
        yield 'unitless 1' => ['<11', '1', '<11'];
        yield 'differs by case' => ['5 PERCENTpercent', 'PERCENT', '5 PERCENTpercent'];
        yield 'no unit' => ['5 PERCENTPERCENT', null, '5 PERCENTPERCENT'];
        // Both repairs, as the two migrations apply them in turn.
        yield 'doubled unit-only text' => ['mIU/mLmIU/mL', 'mIU/mL', 'mIU/mL'];
        yield 'doubled text, no unit' => ['NEGNEG', null, 'NEG'];
        yield 'null text' => [null, 'mg/dL', null];
    }

    #[DataProvider('unitCases')]
    public function test_repaired_applies_both_rules_and_only_to_the_rows_own_unit(mixed $stored, mixed $unit, mixed $expected): void
    {
        $this->assertSame($expected, PhrLabReferenceRangeText::repaired($stored, $unit));
        // A repaired value is a fixed point.
        $this->assertSame($expected, PhrLabReferenceRangeText::repaired($expected, $unit));
    }

    #[DataProvider('cases')]
    public function test_it_returns_the_single_copy_only_of_a_string_written_twice(mixed $stored, ?string $expected): void
    {
        $this->assertSame($expected, PhrLabReferenceRangeText::undoubled($stored));
    }
}
