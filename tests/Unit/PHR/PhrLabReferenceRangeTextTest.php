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

    #[DataProvider('cases')]
    public function test_it_returns_the_single_copy_only_of_a_string_written_twice(mixed $stored, ?string $expected): void
    {
        $this->assertSame($expected, PhrLabReferenceRangeText::undoubled($stored));
    }
}
