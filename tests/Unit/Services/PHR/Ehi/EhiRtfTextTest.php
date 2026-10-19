<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Services\PHR\Import\Ehi\EhiRtfText;
use App\Services\PHR\Import\Ehi\EhiRtfTextResult;
use PHPUnit\Framework\TestCase;

class EhiRtfTextTest extends TestCase
{
    private const string PLANTED = 'PLANTED-MARKER-789';

    private const int CAP = 1024 * 1024;

    private function convert(string $rtf, int $cap = self::CAP): EhiRtfTextResult
    {
        return (new EhiRtfText)->toText($rtf, $cap);
    }

    public function test_plain_paragraphs_tabs_and_escapes(): void
    {
        $result = $this->convert('{\\rtf1\\ansi\\deff0 Hello\\par World\\tab end \\{braces\\} back\\\\slash\\line next}');

        self::assertSame("Hello\nWorld\tend {braces} back\\slash\nnext", $result->text);
        self::assertSame(EhiRtfText::VERSION, $result->version);
        self::assertSame('ehi-rtf-1', $result->version);
        self::assertFalse($result->unsupported);
        self::assertFalse($result->malformed);
        self::assertFalse($result->truncated);
    }

    public function test_source_line_breaks_are_not_text_but_backslash_newline_is_a_paragraph(): void
    {
        self::assertSame("one two\nthree", $this->convert("{\\rtf1 one\r\n two\\\nthree}")->text);
    }

    public function test_unicode_with_fallback_skip_and_surrogate_pairs(): void
    {
        $result = $this->convert('{\\rtf1\\ansi caf\\u233?{\\uc2 \\u8364\\\'80X} ok \\u-10179?\\u-8704? done}');

        self::assertSame("café€ ok \u{1F600} done", $result->text);
        self::assertStringNotContainsString('?', $result->text);
    }

    public function test_uc_is_scoped_to_its_group(): void
    {
        self::assertSame('aÀbcÀy', $this->convert('{\\rtf1 a{\\uc3\\u192 ABCb}c\\u192 xy}')->text);
    }

    public function test_hex_bytes_use_code_page_1252(): void
    {
        $result = $this->convert("{\\rtf1\\ansi\\ansicpg1252 \\'93quoted\\'94 \\'e9t\\'e9 \\'80 \\'81}");

        self::assertSame("\u{201C}quoted\u{201D} été € \u{FFFD}", $result->text);
    }

    public function test_unsupported_code_page_yields_replacement_never_a_guess(): void
    {
        self::assertSame("A\u{FFFD}B", $this->convert("{\\rtf1\\ansi\\ansicpg932 A\\'82B}")->text);
        self::assertSame("A\u{FFFD}B", $this->convert("{\\rtf1\\mac A\\'8eB}")->text);
    }

    public function test_nested_groups_keep_text_in_order(): void
    {
        self::assertSame('a bold italic c', $this->convert('{\\rtf1 a {\\b bold {\\i italic}} c}')->text);
    }

    public function test_skipped_destinations_never_reach_the_output(): void
    {
        $p = self::PLANTED;
        $rtf = '{\\rtf1\\ansi'
            ."{\\fonttbl{\\f0 {$p} Font;}}"
            .'{\\colortbl;\\red1\\green2\\blue3;}'
            ."{\\stylesheet{\\s0 {$p} Style;}}"
            ."{\\info{\\author {$p}}{\\title {$p}}}"
            ."{\\*\\generator {$p};}"
            ."{\\*\\themedata {$p}}"
            ."{\\*\\datastore {$p}}"
            .'Visible '
            ."{\\pict\\pngblip\\picw10 89504e47{$p}}"
            ."{\\*\\shppict{\\pict {$p}}}{\\nonshppict{\\pict {$p}}}"
            ."{\\object\\objemb{\\*\\objclass {$p}}{\\*\\objdata 0105{$p}}{\\result {$p}}}"
            ."{\\*\\unknowndest {$p}}"
            .'text}';
        $result = $this->convert($rtf);

        self::assertSame('Visible text', $result->text);
        self::assertStringNotContainsString($p, $result->text);
        self::assertTrue($result->hadPictures);
        self::assertTrue($result->hadEmbeddedObjects);
        self::assertFalse($result->hadFields);
    }

    public function test_fields_keep_only_their_result(): void
    {
        $p = self::PLANTED;
        $result = $this->convert("{\\rtf1 See {\\field{\\*\\fldinst HYPERLINK \"https://{$p}.invalid\"}{\\fldrslt shown link}} now}");

        self::assertSame('See shown link now', $result->text);
        self::assertStringNotContainsString($p, $result->text);
        self::assertTrue($result->hadFields);
    }

    public function test_binary_data_is_skipped_by_length(): void
    {
        $p = self::PLANTED;
        $blob = "}{\\par {$p}\x00\xff";
        $rtf = '{\\rtf1 before {\\*\\objdata \\bin'.strlen($blob).' '.$blob.'} middle \\bin'.strlen($p).' '.$p.'after}';
        $result = $this->convert($rtf);

        self::assertSame('before  middle after', $result->text);
        self::assertFalse($result->malformed);
    }

    public function test_binary_length_beyond_the_input_is_malformed_and_bounded(): void
    {
        $result = $this->convert('{\\rtf1 kept \\bin9999999999 '.self::PLANTED.'}');

        self::assertSame('kept', $result->text);
        self::assertTrue($result->malformed);
    }

    public function test_not_rtf_is_unsupported_without_exception(): void
    {
        foreach (['', 'plain text', '<html>{\\rtf1 x}</html>', "\x00\x01binary", '{\\rt'] as $input) {
            $result = $this->convert($input);
            self::assertTrue($result->unsupported);
            self::assertSame('', $result->text);
        }
        self::assertFalse($this->convert(" \r\n{\\rtf1 lead}")->unsupported, 'leading whitespace is allowed');
    }

    public function test_depth_bomb_is_unsupported(): void
    {
        $bomb = '{\\rtf1 '.str_repeat('{', 100000).self::PLANTED.str_repeat('}', 100000).'}';
        $result = $this->convert($bomb);

        self::assertTrue($result->unsupported);
        self::assertSame('', $result->text);

        $ok = '{\\rtf1 '.str_repeat('{', EhiRtfText::MAX_DEPTH - 1).'deep'.str_repeat('}', EhiRtfText::MAX_DEPTH - 1).'}';
        self::assertSame('deep', $this->convert($ok)->text);
    }

    public function test_unterminated_and_unbalanced_groups_are_malformed_but_recovered(): void
    {
        $open = $this->convert('{\\rtf1 start {\\b bold');
        self::assertSame('start bold', $open->text);
        self::assertTrue($open->malformed);
        self::assertFalse($open->unsupported);

        $trailing = $this->convert('{\\rtf1 done}} '.self::PLANTED);
        self::assertSame('done', $trailing->text, 'content after the root group is ignored');

        $cut = $this->convert("{\\rtf1 a\\'4");
        self::assertTrue($cut->malformed);

        self::assertTrue($this->convert('{\\rtf1 x\\')->malformed);
    }

    public function test_output_cap_truncates_on_a_character_boundary(): void
    {
        $result = $this->convert('{\\rtf1 '.str_repeat('\\u233?', 100).'}', 9);

        self::assertTrue($result->truncated);
        self::assertSame(str_repeat('é', 4), $result->text);
        self::assertTrue(mb_check_encoding($result->text, 'UTF-8'));
    }

    public function test_huge_input_is_capped(): void
    {
        $body = str_repeat('a', EhiRtfText::MAX_INPUT_BYTES);
        $result = $this->convert('{\\rtf1 '.$body.'TAIL}', EhiRtfText::MAX_INPUT_BYTES * 2);

        self::assertTrue($result->truncated);
        self::assertStringNotContainsString('TAIL', $result->text);
        self::assertLessThanOrEqual(EhiRtfText::MAX_OUTPUT_BYTES, strlen($result->text));
        self::assertFalse($result->malformed);
    }

    public function test_hostile_controls_are_removed_from_output(): void
    {
        $rtf = "{\\rtf1 a\\u0?b\\u27?c\\u8238?d\\u-2?e\\u65535?f\\'07g\\u-10179?h\x01i}";
        $result = $this->convert($rtf);

        self::assertSame("abcd\u{FFFD}e\u{FFFD}fg\u{FFFD}hi", $result->text);
        self::assertSame(0, preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $result->text));
    }

    public function test_long_control_words_and_parameters_are_bounded(): void
    {
        $rtf = '{\\rtf1 '.'\\'.str_repeat('z', 5000).'99999999999999999999 kept \\u99999999999? x}';
        $result = $this->convert($rtf);

        self::assertSame("kept \u{FFFD} x", $result->text);
    }

    public function test_conversion_is_deterministic_and_stateless(): void
    {
        $converter = new EhiRtfText;
        $rtf = "{\\rtf1\\ansi\\uc2 {\\fonttbl{\\f0 x;}}Line\\par \\u8364\\'80\\'80 {\\pict ab}end}";
        $first = $converter->toText($rtf, self::CAP);
        $converter->toText('{\\rtf1\\mac\\uc0 {{{ other', 5);
        $second = $converter->toText($rtf, self::CAP);

        self::assertEquals($first, $second);
        self::assertSame("Line\n€ end", $first->text);
        self::assertSame(['version' => 'ehi-rtf-1', 'truncated' => false, 'embedded_objects' => false, 'pictures' => true, 'fields' => false, 'unsupported' => false, 'malformed' => false], $first->flags());
    }
}
