<?php

namespace Tests\Unit\Services\PHR\Ndc;

use App\Services\PHR\Ndc\NdcNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NdcNumberTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function candidates(): array
    {
        return [
            // Hyphenated ten-digit input is exact, in each of the three layouts.
            '4-4-2 hyphenated' => ['0002-0800-01', ['0002-0800-01']],
            '5-3-2 hyphenated' => ['68180-518-01', ['68180-518-01']],
            '5-4-1 hyphenated' => ['60505-0134-0', ['60505-0134-0']],
            // Ten digits without hyphens could be any of the three layouts.
            '10 digits, no hyphens' => ['6050501340', ['6050-5013-40', '60505-013-40', '60505-0134-0']],
            // Spaces are stripped like hyphens, so the grouping is not evidence of the layout.
            '10 digits with spaces' => ['60505 0134 0', ['6050-5013-40', '60505-013-40', '60505-0134-0']],
            // Eleven digits: the owner's hard-coded rule, first matching segment wins.
            'rule 1, first segment padded' => ['00299-5918-25', ['0299-5918-25']],
            'rule 2, middle segment padded' => ['54868-0925-02', ['54868-925-02']],
            'rule 3, last segment padded' => ['58151-1557-07', ['58151-1557-7']],
            'rule 2 wins over rule 3' => ['60505-0134-00', ['60505-134-00']],
            'rule 1 wins over rules 2 and 3' => ['00002-0800-01', ['0002-0800-01']],
            '11 digits without hyphens' => ['00071015523', ['0071-0155-23']],
            '11 digits, product padded' => ['68180051801', ['68180-518-01']],
            '11 digits, nothing to drop' => ['58151155770', []],
            'NDC prefix and dashes' => ['NDC: 0002‑0800‑01', ['0002-0800-01']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('candidates')]
    public function test_it_generates_every_ten_digit_reading(string $input, array $expected): void
    {
        $this->assertSame($expected, NdcNumber::packageCandidates($input));
    }

    /** @return array<string, array{string}> */
    public static function notAnNdc(): array
    {
        return [
            'empty' => [''],
            'letters' => ['ABCDE-FGHI-JK'],
            'nine digits' => ['123456789'],
            'twelve digits' => ['123456789012'],
            'two segments' => ['12345-67890'],
            'four segments' => ['1-2-3-4'],
            'a segment layout that is not an NDC' => ['123-4567-890'],
            'an empty segment' => ['12345--123'],
        ];
    }

    #[DataProvider('notAnNdc')]
    public function test_other_input_has_no_candidates(string $input): void
    {
        $this->assertFalse(NdcNumber::parse($input)['valid']);
        $this->assertSame([], NdcNumber::packageCandidates($input));
    }

    /** @return array<string, array{string, ?string}> */
    public static function tenDigitForms(): array
    {
        return [
            'the owner example 00299-5918-25' => ['00299-5918-25', '0299-5918-25'],
            'the owner example 54868-0925-02' => ['54868-0925-02', '54868-925-02'],
            'the owner example 60505-0134-00' => ['60505-0134-00', '60505-134-00'],
            'eleven digits without hyphens' => ['00299591825', '0299-5918-25'],
            'hyphenated ten digits are kept' => ['0071-0155-23', '0071-0155-23'],
            'ten digits without hyphens have no layout' => ['0071015523', null],
            'eleven digits with no padded zero' => ['58151155770', null],
            'not an NDC' => ['12345', null],
        ];
    }

    #[DataProvider('tenDigitForms')]
    public function test_the_ten_digit_form_follows_the_hard_coded_rule(string $input, ?string $expected): void
    {
        $this->assertSame($expected, NdcNumber::toTenDigit($input));
    }

    public function test_the_eleven_digit_form_pads_each_segment(): void
    {
        $this->assertSame('60505013400', NdcNumber::toNdc11('60505-0134-0'));
        $this->assertSame('00002080001', NdcNumber::toNdc11('0002-0800-01'));
        $this->assertSame('68180051801', NdcNumber::toNdc11('68180-518-01'));
    }

    public function test_the_product_key_ignores_the_package(): void
    {
        $this->assertSame('68180-0518', NdcNumber::productKey('68180-518-01'));
        $this->assertSame('00002-0800', NdcNumber::productKey('0002-0800-01'));
        $this->assertNull(NdcNumber::productKey('nonsense'));
    }
}
