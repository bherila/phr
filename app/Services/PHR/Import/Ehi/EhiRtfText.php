<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * Pinned, in-process RTF to plain-text converter for EHI note members.
 *
 * The input is untrusted. This is a bounded, single-pass tokenizer, not an RTF
 * renderer: it never interprets embedded objects, never follows links or fields,
 * never fetches anything, and never executes anything. Every input byte is
 * visited at most once (linear time), group depth and input/output sizes are
 * hard-capped, and nothing throws on hostile or malformed input.
 *
 * Rules (VERSION ehi-rtf-1; bump on any change to the output for the same input):
 * - The first non-whitespace bytes must be `{\rtf`; anything else is unsupported.
 * - Input beyond MAX_INPUT_BYTES is not read (`truncated`). Output stops at the
 *   smaller of the caller's cap and MAX_OUTPUT_BYTES, on a character boundary
 *   (`truncated`). Group depth beyond MAX_DEPTH is unsupported.
 * - Text comes only from the body. These destinations are skipped with all their
 *   content: any `{\* ...}` ignorable destination, fonttbl, colortbl, stylesheet,
 *   info, pict, shppict, nonshppict, object, objdata, objclass, themedata,
 *   datastore, colorschememapping, latentstyles, listtable, listoverridetable,
 *   rsidtbl, generator, xmlnstbl, filetbl, revtbl, header/footer variants, shp,
 *   and fldinst. A field keeps only its \fldrslt text.
 * - \binN skips exactly N raw bytes (bounded by the input) wherever it appears.
 * - \par, \line, \sect, \page, \row emit a newline; \tab and \cell emit a tab.
 * - \uN emits code point N (negative N + 65536; surrogate pairs combined) and
 *   then skips the next \ucN fallback characters (default 1) without crossing a
 *   group boundary.
 * - \'hh and raw bytes >= 0x80 decode through the declared ANSI code page. Only
 *   Windows-1252 is supported (also the default when \ansicpg is absent);
 *   \mac, \pc, \pca, and any other \ansicpg decode such bytes as U+FFFD rather
 *   than guessing. Per-font \fcharset is not applied.
 * - Output is sanitized: C0/C1 controls other than tab and newline, bidi
 *   embedding/override/isolate controls, unpaired surrogates, and noncharacters
 *   are removed or replaced with U+FFFD; trailing spaces on each line and
 *   leading/trailing blank space are trimmed.
 * - Unbalanced groups or a cut-off \bin/escape set `malformed` but keep the text
 *   recovered so far; content after the root group closes is ignored.
 */
final class EhiRtfText
{
    public const string VERSION = 'ehi-rtf-1';

    public const int MAX_INPUT_BYTES = 16 * 1024 * 1024;

    public const int MAX_OUTPUT_BYTES = 4 * 1024 * 1024;

    public const int MAX_DEPTH = 64;

    private const int MAX_UC = 8;

    /** Destinations whose content is never text, or never trusted as text. */
    private const array SKIPPED_DESTINATIONS = [
        'fonttbl' => true, 'colortbl' => true, 'stylesheet' => true, 'info' => true,
        'pict' => true, 'shppict' => true, 'nonshppict' => true,
        'object' => true, 'objdata' => true, 'objclass' => true,
        'themedata' => true, 'datastore' => true, 'colorschememapping' => true,
        'latentstyles' => true, 'listtable' => true, 'listoverridetable' => true,
        'rsidtbl' => true, 'generator' => true, 'xmlnstbl' => true, 'filetbl' => true,
        'revtbl' => true, 'fldinst' => true, 'shp' => true,
        'header' => true, 'headerl' => true, 'headerr' => true, 'headerf' => true,
        'footer' => true, 'footerl' => true, 'footerr' => true, 'footerf' => true,
    ];

    private const array SYMBOL_WORDS = [
        'emdash' => 0x2014, 'endash' => 0x2013, 'bullet' => 0x2022,
        'lquote' => 0x2018, 'rquote' => 0x2019, 'ldblquote' => 0x201C, 'rdblquote' => 0x201D,
        'emspace' => 0x2003, 'enspace' => 0x2002, 'qmspace' => 0x2005,
    ];

    /** Windows-1252 0x80-0x9F; null marks the five undefined positions. */
    private const array CP1252_HIGH = [
        0x20AC, null, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021,
        0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, null, 0x017D, null,
        null, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014,
        0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, null, 0x017E, 0x0178,
    ];

    private string $out = '';

    private int $maxOutput = 0;

    private bool $stop = false;

    private bool $truncated = false;

    private bool $malformed = false;

    private bool $unsupported = false;

    private bool $objects = false;

    private bool $pictures = false;

    private bool $fields = false;

    /** Windows code page for \'hh and high bytes; 0 means unsupported. */
    private int $codepage = 1252;

    private bool $skip = false;

    private int $uc = 1;

    private int $pendingSkip = 0;

    private ?int $highSurrogate = null;

    /** @var list<array{0: bool, 1: int}> */
    private array $stack = [];

    public function toText(string $rtf, int $maxOutputBytes): EhiRtfTextResult
    {
        $this->reset(max(0, min($maxOutputBytes, self::MAX_OUTPUT_BYTES)));

        if (strlen($rtf) > self::MAX_INPUT_BYTES) {
            $rtf = substr($rtf, 0, self::MAX_INPUT_BYTES);
            $this->truncated = true;
        }

        $start = strspn($rtf, " \t\r\n");
        if (substr($rtf, $start, 5) !== '{\\rtf') {
            return $this->unsupportedResult();
        }

        $closed = $this->parse($rtf, $start);
        if ($this->unsupported) {
            return $this->unsupportedResult();
        }
        if (! $closed && ! $this->stop && ! $this->truncated) {
            $this->malformed = true;
        }
        $this->flushSurrogate();

        $text = preg_replace('/[ \t]+$/m', '', $this->out) ?? '';
        $text = trim($text, " \t\n");

        return new EhiRtfTextResult(
            text: $text,
            version: self::VERSION,
            truncated: $this->truncated,
            hadEmbeddedObjects: $this->objects,
            hadPictures: $this->pictures,
            hadFields: $this->fields,
            unsupported: false,
            malformed: $this->malformed,
        );
    }

    private function reset(int $maxOutput): void
    {
        $this->out = '';
        $this->maxOutput = $maxOutput;
        $this->stop = false;
        $this->truncated = false;
        $this->malformed = false;
        $this->unsupported = false;
        $this->objects = false;
        $this->pictures = false;
        $this->fields = false;
        $this->codepage = 1252;
        $this->skip = false;
        $this->uc = 1;
        $this->pendingSkip = 0;
        $this->highSurrogate = null;
        $this->stack = [];
    }

    private function unsupportedResult(): EhiRtfTextResult
    {
        return new EhiRtfTextResult(
            text: '',
            version: self::VERSION,
            truncated: $this->truncated,
            hadEmbeddedObjects: $this->objects,
            hadPictures: $this->pictures,
            hadFields: $this->fields,
            unsupported: true,
        );
    }

    /** @return bool whether the root group closed */
    private function parse(string $s, int $i): bool
    {
        $n = strlen($s);
        while ($i < $n && ! $this->stop) {
            $c = $s[$i];
            if ($c === '{') {
                if (count($this->stack) >= self::MAX_DEPTH) {
                    $this->unsupported = true;
                    $this->stop = true;

                    return false;
                }
                $this->stack[] = [$this->skip, $this->uc];
                $this->pendingSkip = 0;
                $i++;

                continue;
            }
            if ($c === '}') {
                $this->pendingSkip = 0;
                $state = array_pop($this->stack);
                $i++;
                if ($state === null) {
                    $this->malformed = true;

                    return true;
                }
                [$this->skip, $this->uc] = $state;
                if ($this->stack === []) {
                    return true;
                }

                continue;
            }
            if ($c === '\\') {
                $i = $this->control($s, $i + 1, $n);

                continue;
            }
            if ($c === "\r" || $c === "\n") {
                $i++;

                continue;
            }

            $length = strcspn($s, "{}\\\r\n", $i);
            $this->run(substr($s, $i, $length));
            $i += $length;
        }

        return false;
    }

    /** Handle one control word, symbol, or \'hh escape starting after the backslash. */
    private function control(string $s, int $i, int $n): int
    {
        if ($i >= $n) {
            $this->malformed = true;

            return $n;
        }
        $c = $s[$i];

        if (ctype_alpha($c)) {
            $length = strspn($s, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $i);
            $word = substr($s, $i, min($length, 32));
            $i += $length;
            $negative = false;
            if ($i + 1 < $n && $s[$i] === '-' && ctype_digit($s[$i + 1])) {
                $negative = true;
                $i++;
            }
            $param = null;
            $digits = strspn($s, '0123456789', $i);
            if ($digits > 0) {
                $param = (int) substr($s, $i, min($digits, 10));
                $param = $negative ? -$param : $param;
                $i += $digits;
            }
            if ($i < $n && $s[$i] === ' ') {
                $i++;
            }
            if ($word === 'bin') {
                if ($this->pendingSkip > 0) {
                    $this->pendingSkip--;
                }
                $i += max(0, $param ?? 0);
                if ($i > $n) {
                    $this->malformed = true;

                    return $n;
                }

                return $i;
            }
            $this->word($word, $param);

            return $i;
        }

        if ($c === "'") {
            $hex = substr($s, $i + 1, 2);
            if (strlen($hex) !== 2 || ! ctype_xdigit($hex)) {
                $this->malformed = true;

                return $i + 1;
            }
            if ($this->pendingSkip > 0) {
                $this->pendingSkip--;
            } elseif (! $this->skip) {
                $this->byte((int) hexdec($hex));
            }

            return $i + 3;
        }

        if ($c === '*') {
            $this->skip = true;

            return $i + 1;
        }
        if ($this->pendingSkip > 0) {
            $this->pendingSkip--;

            return $i + 1;
        }
        if ($this->skip) {
            return $i + 1;
        }
        match ($c) {
            '\\', '{', '}' => $this->emit($c),
            '~' => $this->codepoint(0x00A0),
            '_' => $this->emit('-'),
            "\n", "\r" => $this->emit("\n"),
            default => null,
        };

        return $i + 1;
    }

    private function word(string $word, ?int $param): void
    {
        // Flags record what the document carried, even inside skipped groups.
        if ($word === 'pict' || $word === 'shppict' || $word === 'nonshppict') {
            $this->pictures = true;
        } elseif ($word === 'object' || $word === 'objdata' || $word === 'objclass') {
            $this->objects = true;
        } elseif ($word === 'field' || $word === 'fldinst' || $word === 'fldrslt') {
            $this->fields = true;
        }

        if (isset(self::SKIPPED_DESTINATIONS[$word])) {
            $this->pendingSkip = 0;
            $this->skip = true;

            return;
        }
        if ($this->pendingSkip > 0) {
            $this->pendingSkip--;

            return;
        }

        switch ($word) {
            case 'ansi':
                $this->codepage = 1252;

                return;
            case 'ansicpg':
                $this->codepage = $param === 1252 ? 1252 : 0;

                return;
            case 'mac':
            case 'pc':
            case 'pca':
                $this->codepage = 0;

                return;
            case 'uc':
                $this->uc = max(0, min($param ?? 1, self::MAX_UC));

                return;
        }

        if ($this->skip) {
            return;
        }

        switch ($word) {
            case 'u':
                $code = $param ?? 0;
                $this->codepoint($code < 0 ? $code + 65536 : $code);
                $this->pendingSkip = $this->uc;

                return;
            case 'par':
            case 'line':
            case 'sect':
            case 'page':
            case 'row':
                $this->emit("\n");

                return;
            case 'tab':
            case 'cell':
                $this->emit("\t");

                return;
        }
        if (isset(self::SYMBOL_WORDS[$word])) {
            $this->codepoint(self::SYMBOL_WORDS[$word]);
        }
    }

    /** Literal body text between control sequences. */
    private function run(string $bytes): void
    {
        if ($bytes === '' || $this->skip) {
            if ($this->pendingSkip > 0) {
                $this->pendingSkip = max(0, $this->pendingSkip - strlen($bytes));
            }

            return;
        }
        if ($this->pendingSkip > 0) {
            $drop = min($this->pendingSkip, strlen($bytes));
            $this->pendingSkip -= $drop;
            $bytes = substr($bytes, $drop);
            if ($bytes === '') {
                return;
            }
        }
        if (preg_match('/[^\x20-\x7E]/', $bytes) !== 1) {
            $this->emit($bytes);

            return;
        }
        $length = strlen($bytes);
        for ($k = 0; $k < $length && ! $this->stop; $k++) {
            $this->byte(ord($bytes[$k]));
        }
    }

    /** One byte in the document's ANSI code page. */
    private function byte(int $byte): void
    {
        if ($byte < 0x80) {
            if ($byte === 0x09) {
                $this->emit("\t");
            } elseif ($byte >= 0x20 && $byte !== 0x7F) {
                $this->emit(chr($byte));
            }

            return;
        }
        if ($this->codepage !== 1252) {
            $this->codepoint(0xFFFD);

            return;
        }
        $this->codepoint($byte < 0xA0 ? (self::CP1252_HIGH[$byte - 0x80] ?? 0xFFFD) : $byte);
    }

    private function codepoint(int $code): void
    {
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            $this->flushSurrogate();
            $this->highSurrogate = $code;

            return;
        }
        if ($code >= 0xDC00 && $code <= 0xDFFF) {
            if ($this->highSurrogate === null) {
                $this->emit("\u{FFFD}");

                return;
            }
            $combined = 0x10000 + (($this->highSurrogate - 0xD800) << 10) + ($code - 0xDC00);
            $this->highSurrogate = null;
            $this->emit((string) mb_chr($combined, 'UTF-8'));

            return;
        }
        if ($code === 0x09 || $code === 0x0A) {
            $this->emit(chr($code));

            return;
        }
        if ($code < 0x20 || ($code >= 0x7F && $code <= 0x9F)
            || ($code >= 0x202A && $code <= 0x202E) || ($code >= 0x2066 && $code <= 0x2069)) {
            return;
        }
        if ($code > 0x10FFFF || ($code & 0xFFFE) === 0xFFFE || ($code >= 0xFDD0 && $code <= 0xFDEF)) {
            $code = 0xFFFD;
        }
        $this->emit((string) mb_chr($code, 'UTF-8'));
    }

    private function flushSurrogate(): void
    {
        if ($this->highSurrogate !== null) {
            $this->highSurrogate = null;
            $this->append("\u{FFFD}");
        }
    }

    private function emit(string $utf8): void
    {
        if ($this->stop) {
            return;
        }
        $this->flushSurrogate();
        $this->append($utf8);
    }

    private function append(string $utf8): void
    {
        if ($this->stop) {
            return;
        }
        $room = $this->maxOutput - strlen($this->out);
        if (strlen($utf8) > $room) {
            $this->out .= mb_strcut($utf8, 0, max(0, $room), 'UTF-8');
            $this->truncated = true;
            $this->stop = true;

            return;
        }
        $this->out .= $utf8;
    }
}
