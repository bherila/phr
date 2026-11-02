<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Services\PHR\Import\Ehi\Mapping\Lab\EhiLabValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EhiLabValueTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null, 3: string|null, 4: string|null}>
     */
    public static function values(): array
    {
        return [
            'plain number stays exact' => ['13.20', null, null, '13.20', '13.20'],
            'zero is a value' => ['0', null, null, '0', '0'],
            'negative' => ['-1.5', null, null, '-1.5', '-1.5'],
            'comparator in the text is not a measurement' => ['<0.5', null, '<', '0.5', null],
            'inclusive comparator' => ['>= 10', null, '>=', '10', null],
            'comparator only in the normalized form' => ['50', "<\x1050", '<', '50', null],
            'both agree' => ['<50', "<\x1050.0", '<', '50', null],
            'normalized plain number' => ['50', "\x1050", null, '50', '50'],
            'a stated interval has no single comparator' => ['5-10', ">=\x105\x10<=\x1010", null, null, null],
            'text is never numeric' => ['Positive', null, null, null, null],
            'text with a normalized number is not a measurement' => ['Positive', "\x101", null, '1', null],
            'comma decimal is not read as a number' => ['1,5', null, null, null, null],
            'more fraction digits than the column holds' => ['0.12345678901', null, null, '0.12345678901', null],
            'exactly as many as it holds' => ['0.1234567890', null, null, '0.1234567890', '0.1234567890'],
            'more integer digits than the column holds' => ['123456789', null, null, '123456789', null],
            'absent' => [null, null, null, null, null],
        ];
    }

    #[DataProvider('values')]
    public function test_reads_a_value_without_rounding_or_guessing(?string $value, ?string $normalized, ?string $comparator, ?string $number, ?string $numeric): void
    {
        $parsed = EhiLabValue::parse($value, $normalized);

        self::assertSame($comparator, $parsed['comparator']);
        self::assertSame($number, $parsed['number']);
        self::assertSame($numeric, $parsed['numeric']);
        self::assertFalse($parsed['conflicting']);
    }

    public function test_the_sentinel_numeric_column_is_never_an_input(): void
    {
        self::assertNull(EhiLabValue::parse('Positive', null)['numeric']);
    }

    public function test_a_disagreement_relies_on_neither(): void
    {
        foreach ([['<50', ">\x1050"], ['<50', "<\x1060"], ['50', "\x1051"]] as [$value, $normalized]) {
            $parsed = EhiLabValue::parse($value, $normalized);
            self::assertTrue($parsed['conflicting'], (string) $value);
            self::assertNull($parsed['comparator']);
            self::assertNull($parsed['numeric']);
        }
    }

    public function test_an_unrecognized_normalized_form_is_reported_and_ignored(): void
    {
        $parsed = EhiLabValue::parse('12', "GT\x1012");

        self::assertTrue($parsed['unrecognized']);
        self::assertSame('12', $parsed['numeric']);
    }

    public function test_bounds_only_reach_the_decimal_columns_when_exact(): void
    {
        self::assertSame('70.00', EhiLabValue::exactBound(' 70.00 '));
        self::assertNull(EhiLabValue::exactBound('<5'));
        self::assertNull(EhiLabValue::exactBound('negative'));
        self::assertNull(EhiLabValue::exactBound('1,5'));
        self::assertNull(EhiLabValue::exactBound(null));
    }

    public function test_numerically_equal_values_compare_equal_and_text_folds(): void
    {
        self::assertSame(EhiLabValue::canonical('5'), EhiLabValue::canonical('5.0000000000'));
        self::assertSame(EhiLabValue::canonical('.5'), EhiLabValue::canonical('0.50'));
        self::assertSame('0', EhiLabValue::canonical('-0.0'));
        self::assertSame('-1.5', EhiLabValue::canonical('-1.50'));
        self::assertNotSame(EhiLabValue::canonical('0.1'), EhiLabValue::canonical('0.10000000001'));
        self::assertSame('no growth', EhiLabValue::canonical(' No Growth '));
        self::assertNull(EhiLabValue::canonical('  '));
    }
}
