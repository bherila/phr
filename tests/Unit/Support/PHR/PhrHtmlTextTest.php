<?php

namespace Tests\Unit\Support\PHR;

use App\Support\PHR\PhrHtmlText;
use LibXMLError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which libxml errors mean the parsed tree lost part of the document (#418). The
 * local libxml decides which codes a real oversized or deeply nested page raises, so
 * the codes other versions use are checked here directly.
 */
final class PhrHtmlTextTest extends TestCase
{
    /** @return array<string, array{int, int, bool}> */
    public static function errors(): array
    {
        return [
            'internal error, as libxml before 2.11 reports excessive depth' => [LIBXML_ERR_ERROR, 1, true],
            'no memory, as libxml before 2.11 reports a huge text node' => [LIBXML_ERR_ERROR, 2, true],
            'resource limit, as libxml 2.11 and later report both' => [LIBXML_ERR_ERROR, 114, true],
            'resource limit as a warning' => [LIBXML_ERR_WARNING, 114, true],
            'any fatal error' => [LIBXML_ERR_FATAL, 76, true],
            'an ordinary markup error: unexpected end tag' => [LIBXML_ERR_ERROR, 76, false],
            'an ordinary markup warning' => [LIBXML_ERR_WARNING, 801, false],
        ];
    }

    #[DataProvider('errors')]
    public function test_only_errors_that_drop_content_count_as_truncating(int $level, int $code, bool $truncates): void
    {
        $error = new LibXMLError;
        $error->level = $level;
        $error->code = $code;

        $this->assertSame($truncates, PhrHtmlText::truncates($error));
    }

    public function test_ordinary_html_still_reads_as_text(): void
    {
        $this->assertSame('Synthetic note', trim((string) PhrHtmlText::toText('<p>Synthetic <b>note</b></p><p>')));
    }
}
