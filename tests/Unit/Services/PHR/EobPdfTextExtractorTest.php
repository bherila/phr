<?php

namespace Tests\Unit\Services\PHR;

use App\Services\PHR\Import\EobPdfTextExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class EobPdfTextExtractorTest extends TestCase
{
    public function test_bounded_extraction_preserves_text_at_the_exact_byte_limit(): void
    {
        self::assertSame('Synthetic text', $this->extractor('echo "Synthetic text";')->extractBounded('unused', 14));
    }

    public function test_large_stdout_is_stopped_at_the_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the diagnostic limit');
        $this->extractor('echo str_repeat("x", 4096);')->extractBounded('unused', 100);
    }

    public function test_a_failed_extractor_never_echoes_its_stderr(): void
    {
        try {
            $this->extractor('fwrite(STDERR, "Synthetic private data"); exit(1);')->extractBounded('unused', 100);
            self::fail('A failed process cannot verify source text.');
        } catch (RuntimeException $failure) {
            self::assertSame('pdftotext failed.', $failure->getMessage());
        }
    }

    public function test_empty_text_does_not_count_as_verified_source_text(): void
    {
        $this->expectExceptionMessage('no extractable text');
        $this->extractor('echo "  ";')->extractBounded('unused', 100);
    }

    public function test_the_bound_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->extractor('echo "text";')->extractBounded('unused', 0);
    }

    private function extractor(string $code): EobPdfTextExtractor
    {
        return new class($code) extends EobPdfTextExtractor
        {
            public function __construct(private string $code) {}

            protected function textProcess(string $path): Process
            {
                return new Process([PHP_BINARY, '-r', $this->code]);
            }
        };
    }
}
