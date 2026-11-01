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
            // Eleven digits: drop one leading zero from each segment that has one.
            '11 digits, only the package is padded' => ['60505013400', ['60505-134-00', '60505-0134-0']],
            'the issue example 60505-0134-00' => ['60505-0134-00', ['60505-134-00', '60505-0134-0']],
            'the issue example 00002-0800-01' => ['00002-0800-01', ['0002-0800-01', '00002-800-01', '00002-0800-1']],
            '11 digits, labeler padded' => ['00071015523', ['0071-0155-23', '00071-155-23']],
            '11 digits, product padded' => ['68180051801', ['68180-518-01', '68180-0518-1']],
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
