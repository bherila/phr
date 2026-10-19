<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiTableAccount;
use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * Streams one EHI table member under an explicit EhiDialect. Memory is bounded by
 * one read chunk plus EhiLimits::$maxRowBytes: a longer record is skipped, never
 * buffered, and reported as row_too_large.
 *
 * Record rules:
 * - A record ends at an LF outside quotes; a CR immediately before it belongs to
 *   the terminator. Any other CR is data (counted in `records_with_lone_cr`).
 * - In quote mode `none`, quotes are data (counted, never interpreted). In
 *   `rfc4180`, a field starting with `"` is quoted: it may hold the delimiter, LF
 *   and `""` (one quote); a quote anywhere else is stray_quote.
 * - Each record is validated as UTF-8 and never transcoded. A BOM is recognized
 *   on the header only.
 * - Values stay raw strings. An empty cell is ""; null only where the dialect's
 *   null marker matched an unquoted cell; a record with fewer or more fields than
 *   the header is field_count_mismatch, never padded.
 * - After an unusable header, every remaining record is still read (under the
 *   same row limit) and yielded as header_unusable, so each one is counted.
 *
 * Locators are the physical (LF-counted, 1-based) line and byte offset of the
 * record start. Neither a row nor an exception ever carries record bytes.
 */
final class EhiTsvReader
{
    public const string HEADER_EMPTY_COLUMN = 'empty_column_name';

    public const string HEADER_DUPLICATE_COLUMN = 'duplicate_column_name';

    public const string HEADER_UNEXPECTED_BOM = 'unexpected_bom';

    public const string BOM = "\xEF\xBB\xBF";

    /** Counter keys returned by counters(); all are always present. */
    public const array COUNTERS = [
        'bytes',
        'records',
        'terminator_lf',
        'terminator_crlf',
        'terminator_none',
        'records_with_lone_cr',
        'records_with_leading_quote',
        'records_with_embedded_quote',
        'empty_cells',
        'null_marker_cells',
    ];

    private const int CHUNK_BYTES = 64 * 1024;

    /** @var resource */
    private $stream;

    private string $buffer = '';

    /** Absolute offset of $buffer[0] within the member. */
    private int $bufferOffset = 0;

    /** Index in $buffer of the first unconsumed byte. */
    private int $pos = 0;

    /** Physical line of the first unconsumed byte. */
    private int $line = 1;

    private bool $eof = false;

    private bool $headerRead = false;

    /** @var list<string>|null */
    private ?array $header = null;

    /** @var list<string|null>|null fields of the header record, even when unusable; null when it could not be split */
    private ?array $headerFields = null;

    private ?string $headerProblem = null;

    private bool $bom = false;

    private string $state = PhrEhiTableAccount::STATE_EMPTY;

    private bool $rowsStarted = false;

    /** @var array<string, int> */
    private array $counters;

    /**
     * @param  resource  $stream  readable stream positioned at the member start
     */
    public function __construct($stream, private readonly EhiDialect $dialect, private readonly EhiLimits $limits)
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('EHI table reader requires a readable stream.');
        }
        if ($limits->maxRowBytes < 1 || $limits->maxValueBytes < 1) {
            throw new InvalidArgumentException('EHI row and value limits must be positive.');
        }
        $this->stream = $stream;
        $this->counters = array_fill_keys(self::COUNTERS, 0);
        foreach (EhiTsvMalformedRow::CODES as $code) {
            $this->counters['malformed_'.$code] = 0;
        }
    }

    /**
     * PhrEhiTableAccount::STATE_EMPTY (zero bytes), STATE_MALFORMED (no usable
     * header), STATE_HEADER_ONLY, or STATE_PARSED (at least one record follows the
     * header, well-formed or not).
     */
    public function state(): string
    {
        $this->readHeader();

        return $this->state;
    }

    /** @return list<string>|null column names, or null when the member has no usable header */
    public function header(): ?array
    {
        $this->readHeader();

        return $this->header;
    }

    /** Fixed code explaining STATE_MALFORMED: a malformed-row code or a HEADER_* code. */
    public function headerProblem(): ?string
    {
        $this->readHeader();

        return $this->headerProblem;
    }

    /**
     * Whether the header record names this column, even when the header is
     * otherwise unusable (an empty or duplicate name); null when the header
     * record could not be split at all, so the answer is unknown.
     */
    public function declaresColumn(string $name): ?bool
    {
        $this->readHeader();
        if ($this->state === PhrEhiTableAccount::STATE_EMPTY) {
            return false;
        }

        return $this->headerFields === null ? null : in_array($name, $this->headerFields, true);
    }

    public function hasBom(): bool
    {
        $this->readHeader();

        return $this->bom;
    }

    /**
     * Structural counters over everything read so far (complete once rows() is
     * exhausted). Line-ending counters include the header record.
     *
     * @return array<string, int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * Every record after the header, in file order. Iterable once. After an
     * unusable header each record is a header_unusable EhiTsvMalformedRow.
     *
     * @return Generator<int, EhiTsvRow|EhiTsvMalformedRow, mixed, void>
     */
    public function rows(): Generator
    {
        if ($this->rowsStarted) {
            throw new LogicException('EHI table rows can only be iterated once.');
        }
        $this->rowsStarted = true;
        $this->readHeader();
        if ($this->header === null) {
            // Records are bounded by nextRecord() and never split into values.
            while (($record = $this->nextRecord()) !== null) {
                $this->counters['records']++;
                $this->counters['malformed_'.EhiTsvMalformedRow::HEADER_UNUSABLE]++;

                yield new EhiTsvMalformedRow($record['line'], $record['offset'], EhiTsvMalformedRow::HEADER_UNUSABLE);
            }

            return;
        }

        $expected = count($this->header);
        while (($record = $this->nextRecord()) !== null) {
            $this->counters['records']++;
            $row = $this->toRow($record, $expected);
            if ($row instanceof EhiTsvMalformedRow) {
                $this->counters['malformed_'.$row->code]++;
            }

            yield $row;
        }
    }

    private function readHeader(): void
    {
        if ($this->headerRead) {
            return;
        }
        $this->headerRead = true;

        $this->ensure(strlen(self::BOM));
        if ($this->available() === 0) {
            $this->state = PhrEhiTableAccount::STATE_EMPTY;

            return;
        }
        if (str_starts_with(substr($this->buffer, $this->pos, strlen(self::BOM)), self::BOM)) {
            $this->bom = true;
            if ($this->dialect->allowBom) {
                $this->pos += strlen(self::BOM);
            }
        }

        $record = $this->nextRecord();
        $this->state = PhrEhiTableAccount::STATE_MALFORMED;
        if ($this->bom && ! $this->dialect->allowBom) {
            $this->headerProblem = self::HEADER_UNEXPECTED_BOM;

            return;
        }
        if ($record === null) {
            // Only a BOM: nothing decodable follows it.
            $this->headerProblem = self::HEADER_EMPTY_COLUMN;

            return;
        }

        $fields = $this->parseRecord($record, false);
        if (is_string($fields)) {
            $this->headerProblem = $fields;

            return;
        }
        $this->headerFields = $fields;
        $names = [];
        $header = [];
        foreach ($fields as $name) {
            if ($name === null || $name === '') {
                $this->headerProblem = self::HEADER_EMPTY_COLUMN;

                return;
            }
            if (isset($names[$name])) {
                $this->headerProblem = self::HEADER_DUPLICATE_COLUMN;

                return;
            }
            $names[$name] = true;
            $header[] = $name;
        }

        $this->header = $header;
        $this->ensure(1);
        $this->state = $this->available() > 0 ? PhrEhiTableAccount::STATE_PARSED : PhrEhiTableAccount::STATE_HEADER_ONLY;
    }

    /**
     * @param  array{offset: int, line: int, raw: string|null, problem: string|null}  $record
     */
    private function toRow(array $record, int $expected): EhiTsvRow|EhiTsvMalformedRow
    {
        $fields = $this->parseRecord($record, true);
        if (is_string($fields)) {
            return new EhiTsvMalformedRow($record['line'], $record['offset'], $fields);
        }
        if (count($fields) !== $expected) {
            return new EhiTsvMalformedRow($record['line'], $record['offset'], EhiTsvMalformedRow::FIELD_COUNT_MISMATCH, $expected, count($fields));
        }
        foreach ($fields as $value) {
            if ($value === null) {
                $this->counters['null_marker_cells']++;
            } elseif ($value === '') {
                $this->counters['empty_cells']++;
            }
        }

        return new EhiTsvRow($record['line'], $record['offset'], $fields);
    }

    /**
     * @param  array{offset: int, line: int, raw: string|null, problem: string|null}  $record
     * @return list<string|null>|string fields, or a malformed-row code
     */
    private function parseRecord(array $record, bool $isData): array|string
    {
        if ($record['problem'] !== null) {
            return $record['problem'];
        }
        $raw = (string) $record['raw'];
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return EhiTsvMalformedRow::INVALID_ENCODING;
        }
        if ($isData) {
            $this->countQuotes($raw);
        }

        return $this->dialect->quoteMode === EhiDialect::QUOTE_RFC4180
            ? $this->splitQuoted($raw, $isData)
            : $this->splitPlain($raw, $isData);
    }

    /** @return list<string|null>|string */
    private function splitPlain(string $raw, bool $applyNullMarker): array|string
    {
        $values = [];
        foreach (explode($this->dialect->delimiter, $raw) as $value) {
            if (strlen($value) > $this->limits->maxValueBytes) {
                return EhiTsvMalformedRow::VALUE_TOO_LARGE;
            }
            $values[] = $applyNullMarker && $value === $this->dialect->nullMarker ? null : $value;
        }

        return $values;
    }

    /** @return list<string|null>|string */
    private function splitQuoted(string $raw, bool $applyNullMarker): array|string
    {
        $delimiter = $this->dialect->delimiter;
        $length = strlen($raw);
        $values = [];
        $i = 0;
        while (true) {
            $quoted = $i < $length && $raw[$i] === '"';
            if ($quoted) {
                $value = '';
                $j = $i + 1;
                while (true) {
                    $quote = strpos($raw, '"', $j);
                    if ($quote === false) {
                        return EhiTsvMalformedRow::UNTERMINATED_QUOTE;
                    }
                    $value .= substr($raw, $j, $quote - $j);
                    if ($quote + 1 < $length && $raw[$quote + 1] === '"') {
                        $value .= '"';
                        $j = $quote + 2;

                        continue;
                    }
                    $i = $quote + 1;

                    break;
                }
            } else {
                $end = strpos($raw, $delimiter, $i);
                $end = $end === false ? $length : $end;
                $value = substr($raw, $i, $end - $i);
                if (str_contains($value, '"')) {
                    return EhiTsvMalformedRow::STRAY_QUOTE;
                }
                $i = $end;
            }

            if (strlen($value) > $this->limits->maxValueBytes) {
                return EhiTsvMalformedRow::VALUE_TOO_LARGE;
            }
            $values[] = $applyNullMarker && ! $quoted && $value === $this->dialect->nullMarker ? null : $value;

            if ($i >= $length) {
                return $values;
            }
            if ($raw[$i] !== $delimiter) {
                // Bytes after a closing quote.
                return EhiTsvMalformedRow::STRAY_QUOTE;
            }
            $i++;
        }
    }

    /** Quote shape counters, independent of the quote mode, so dialects can be compared. */
    private function countQuotes(string $raw): void
    {
        $total = substr_count($raw, '"');
        if ($total === 0) {
            return;
        }
        $leading = preg_match_all('/(?:^|'.preg_quote($this->dialect->delimiter, '/').')"/', $raw);
        if ($leading > 0) {
            $this->counters['records_with_leading_quote']++;
        }
        if ($total > $leading) {
            $this->counters['records_with_embedded_quote']++;
        }
    }

    /**
     * The next physical record, or null at end of input. Oversized records are
     * consumed without being kept.
     *
     * @return array{offset: int, line: int, raw: string|null, problem: string|null}|null
     */
    private function nextRecord(): ?array
    {
        $this->ensure(1);
        if ($this->available() === 0) {
            return null;
        }

        $quoting = $this->dialect->quoteMode === EhiDialect::QUOTE_RFC4180;
        $offset = $this->bufferOffset + $this->pos;
        $line = $this->line;
        $scan = $this->pos;
        $inQuote = false;
        $tooLarge = false;
        $end = null;

        while (true) {
            $length = strlen($this->buffer);
            if ($scan >= $length) {
                if ($this->eof) {
                    break;
                }
                $scan = $this->more($scan, $tooLarge);

                continue;
            }

            if ($inQuote) {
                $quote = strpos($this->buffer, '"', $scan);
                if ($quote === false) {
                    $scan = $length;
                } elseif ($quote + 1 >= $length && ! $this->eof) {
                    // Need the next byte to tell a doubled quote from a closing one.
                    $scan = $this->more($quote, $tooLarge);

                    continue;
                } elseif ($quote + 1 < $length && $this->buffer[$quote + 1] === '"') {
                    $scan = $quote + 2;
                } else {
                    $inQuote = false;
                    $scan = $quote + 1;
                }
            } else {
                $hit = $quoting
                    ? $scan + strcspn($this->buffer, "\"\n", $scan)
                    : strpos($this->buffer, "\n", $scan);
                if ($hit === false || $hit >= $length) {
                    $scan = $length;
                } elseif ($this->buffer[$hit] === '"') {
                    // Only a quote opening a field starts a quoted section; any other
                    // quote is left for the field parser to report as stray.
                    $inQuote = $this->bufferOffset + $hit === $offset || $this->buffer[$hit - 1] === $this->dialect->delimiter;
                    $scan = $hit + 1;
                } else {
                    $end = $hit;

                    break;
                }
            }

            // +1 leaves room for the CR of a CRLF terminator.
            if (! $tooLarge && $scan - $this->pos > $this->limits->maxRowBytes + 1) {
                $tooLarge = true;
            }
        }

        $stop = $end ?? strlen($this->buffer);
        $this->line += substr_count($this->buffer, "\n", $this->pos, $stop - $this->pos);
        $raw = $tooLarge ? null : substr($this->buffer, $this->pos, $stop - $this->pos);
        if ($end !== null) {
            $this->line++;
            $this->pos = $end + 1;
        } else {
            $this->pos = $stop;
        }

        if ($raw !== null && $end !== null && str_ends_with($raw, "\r")) {
            $raw = substr($raw, 0, -1);
            $this->counters['terminator_crlf']++;
        } elseif ($end !== null) {
            $this->counters['terminator_lf']++;
        } else {
            $this->counters['terminator_none']++;
        }
        if ($raw !== null && str_contains($raw, "\r")) {
            $this->counters['records_with_lone_cr']++;
        }

        $problem = null;
        if ($inQuote) {
            $problem = EhiTsvMalformedRow::UNTERMINATED_QUOTE;
        } elseif ($tooLarge || strlen((string) $raw) > $this->limits->maxRowBytes) {
            $problem = EhiTsvMalformedRow::ROW_TOO_LARGE;
            $raw = null;
        }

        return ['offset' => $offset, 'line' => $line, 'raw' => $problem === null ? $raw : null, 'problem' => $problem];
    }

    /**
     * Read another chunk while scanning a record. An oversized record's scanned
     * bytes are dropped first, so the buffer never holds more than one row limit.
     *
     * @return int the scan index translated into the new buffer
     */
    private function more(int $scan, bool $discard): int
    {
        if ($discard && $scan - 1 > $this->pos) {
            // Keep the byte before $scan: the scanner looks back one byte.
            $this->line += substr_count($this->buffer, "\n", $this->pos, $scan - 1 - $this->pos);
            $this->pos = $scan - 1;
        }
        $shift = $this->fill();

        return $scan - $shift;
    }

    private function ensure(int $bytes): void
    {
        while ($this->available() < $bytes && ! $this->eof) {
            $this->fill();
        }
    }

    private function available(): int
    {
        return strlen($this->buffer) - $this->pos;
    }

    /** @return int bytes dropped from the front of the buffer */
    private function fill(): int
    {
        $shift = $this->pos;
        if ($shift > 0) {
            $this->buffer = substr($this->buffer, $shift);
            $this->bufferOffset += $shift;
            $this->pos = 0;
        }

        $chunk = fread($this->stream, self::CHUNK_BYTES);
        if ($chunk === false || $chunk === '') {
            $this->eof = true;
        } else {
            $this->buffer .= $chunk;
            $this->counters['bytes'] += strlen($chunk);
        }

        return $shift;
    }
}
