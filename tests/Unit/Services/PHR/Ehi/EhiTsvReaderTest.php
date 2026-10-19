<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Models\PhrEhiTableAccount;
use App\Services\PHR\Import\Ehi\EhiDialect;
use App\Services\PHR\Import\Ehi\EhiLimits;
use App\Services\PHR\Import\Ehi\EhiTsvMalformedRow;
use App\Services\PHR\Import\Ehi\EhiTsvReader;
use App\Services\PHR\Import\Ehi\EhiTsvRow;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EhiTsvReaderTest extends TestCase
{
    public function test_zero_byte_member_is_empty(): void
    {
        $reader = $this->reader('');

        self::assertSame(PhrEhiTableAccount::STATE_EMPTY, $reader->state());
        self::assertNull($reader->header());
        self::assertSame([], iterator_to_array($reader->rows()));
    }

    public function test_header_only_with_and_without_terminator(): void
    {
        foreach (["A_ID\tB_DATE\n", "A_ID\tB_DATE", "A_ID\tB_DATE\r\n"] as $bytes) {
            $reader = $this->reader($bytes);
            self::assertSame(PhrEhiTableAccount::STATE_HEADER_ONLY, $reader->state());
            self::assertSame(['A_ID', 'B_DATE'], $reader->header());
            self::assertSame([], iterator_to_array($reader->rows()));
        }
    }

    public function test_undecodable_header_is_malformed(): void
    {
        $cases = [
            "A\xFF\tB\n1\t2\n" => [EhiTsvMalformedRow::INVALID_ENCODING, 1],
            "\n1\n" => [EhiTsvReader::HEADER_EMPTY_COLUMN, 1],
            "A\t\tB\n" => [EhiTsvReader::HEADER_EMPTY_COLUMN, 0],
            "A\tA\n" => [EhiTsvReader::HEADER_DUPLICATE_COLUMN, 0],
            EhiTsvReader::BOM => [EhiTsvReader::HEADER_EMPTY_COLUMN, 0],
        ];
        foreach ($cases as $bytes => [$problem, $records]) {
            $reader = $this->reader((string) $bytes);
            self::assertSame(PhrEhiTableAccount::STATE_MALFORMED, $reader->state());
            self::assertSame($problem, $reader->headerProblem());
            self::assertNull($reader->header());
            $rows = iterator_to_array($reader->rows(), false);
            self::assertCount($records, $rows);
            foreach ($rows as $row) {
                self::assertInstanceOf(EhiTsvMalformedRow::class, $row);
                self::assertSame(EhiTsvMalformedRow::HEADER_UNUSABLE, $row->code);
            }
        }
    }

    public function test_records_after_an_unusable_header_are_drained_and_counted(): void
    {
        $bytes = "PAT_ID\tPAT_ID\n1\t2\n".str_repeat('z', 200)."\n\n\"open\t3\r\nlast";
        $reader = $this->reader($bytes, limits: $this->limits(rowBytes: 64, valueBytes: 8));

        self::assertSame(PhrEhiTableAccount::STATE_MALFORMED, $reader->state());
        self::assertSame(EhiTsvReader::HEADER_DUPLICATE_COLUMN, $reader->headerProblem());
        $rows = iterator_to_array($reader->rows(), false);

        // Every physical record is yielded, oversized ones included, none split.
        self::assertSame([2, 3, 4, 5, 6], array_map(fn (EhiTsvMalformedRow $row): int => $row->lineNumber, $rows));
        self::assertSame(strpos($bytes, '"open'), $rows[3]->byteOffset);
        foreach ($rows as $row) {
            self::assertSame(EhiTsvMalformedRow::HEADER_UNUSABLE, $row->code);
            self::assertNull($row->expectedFields);
        }
        $counters = $reader->counters();
        self::assertSame(5, $counters['records']);
        self::assertSame(5, $counters['malformed_header_unusable']);
        self::assertSame(0, $counters['malformed_row_too_large']);
        self::assertSame(strlen($bytes), $counters['bytes']);
        self::assertSame(0, $counters['empty_cells']);
    }

    public function test_drained_oversized_records_are_not_buffered(): void
    {
        $stream = $this->stream("A\tA\n".str_repeat('z', 3 * 1024 * 1024)."\nok\n");
        $before = memory_get_usage();
        $reader = new EhiTsvReader($stream, EhiDialect::provisional(), $this->limits(rowBytes: 1024, valueBytes: 1024));
        $rows = iterator_to_array($reader->rows(), false);

        self::assertCount(2, $rows);
        self::assertSame(3, $rows[1]->lineNumber);
        self::assertLessThan(1024 * 1024, memory_get_usage() - $before);
    }

    public function test_declares_column_even_when_the_header_is_unusable(): void
    {
        self::assertTrue($this->reader("PAT_ID\tX\n")->declaresColumn('PAT_ID'));
        self::assertFalse($this->reader("IMMUNE_ID\tX\n")->declaresColumn('PAT_ID'));
        self::assertTrue($this->reader("PAT_ID\tPAT_ID\n1\t2\n")->declaresColumn('PAT_ID'));
        self::assertTrue($this->reader("PAT_ID\t\tX\n")->declaresColumn('PAT_ID'));
        self::assertFalse($this->reader("A\tA\n")->declaresColumn('PAT_ID'));
        self::assertFalse($this->reader('')->declaresColumn('PAT_ID'));
        self::assertNull($this->reader("PAT_ID\t\xFF\n1\t2\n")->declaresColumn('PAT_ID'), 'an undecodable header cannot say');
        self::assertNull($this->reader(str_repeat('P', 100)."\n", limits: $this->limits(rowBytes: 16))->declaresColumn('PAT_ID'));
    }

    public function test_bom_is_stripped_from_the_header_only(): void
    {
        $reader = $this->reader(EhiTsvReader::BOM."PAT_ID\tNAME\n".EhiTsvReader::BOM."1\tx\n");

        self::assertTrue($reader->hasBom());
        self::assertSame(['PAT_ID', 'NAME'], $reader->header());
        $rows = $this->rows($reader);
        self::assertSame([EhiTsvReader::BOM.'1', 'x'], $rows[0]->values);
        self::assertSame(strlen(EhiTsvReader::BOM."PAT_ID\tNAME\n"), $rows[0]->byteOffset);
    }

    public function test_bom_is_refused_when_the_dialect_disallows_it(): void
    {
        $dialect = new EhiDialect(allowBom: false);
        $reader = $this->reader(EhiTsvReader::BOM."A\tB\n1\t2\n", $dialect);

        self::assertSame(PhrEhiTableAccount::STATE_MALFORMED, $reader->state());
        self::assertSame(EhiTsvReader::HEADER_UNEXPECTED_BOM, $reader->headerProblem());
    }

    public function test_lf_and_crlf_terminators_are_counted_and_stripped(): void
    {
        $reader = $this->reader("A\tB\r\n1\t2\r\n3\t4\n5\t6");
        $rows = $this->rows($reader);

        self::assertSame([['1', '2'], ['3', '4'], ['5', '6']], array_map(fn (EhiTsvRow $row): array => $row->values, $rows));
        self::assertSame([2, 3, 4], array_map(fn (EhiTsvRow $row): int => $row->lineNumber, $rows));
        $counters = $reader->counters();
        self::assertSame(2, $counters['terminator_crlf']);
        self::assertSame(1, $counters['terminator_lf']);
        self::assertSame(1, $counters['terminator_none']);
        self::assertSame(3, $counters['records']);
    }

    public function test_lone_cr_and_quotes_are_data_in_quote_mode_none(): void
    {
        $reader = $this->reader("A\tB\n\"x\ty\"z\n1\ra\t\"q\"\n");
        $rows = $this->rows($reader);

        self::assertSame(['"x', 'y"z'], $rows[0]->values);
        self::assertSame(["1\ra", '"q"'], $rows[1]->values);
        $counters = $reader->counters();
        self::assertSame(1, $counters['records_with_lone_cr']);
        self::assertSame(2, $counters['records_with_leading_quote']);
        self::assertSame(2, $counters['records_with_embedded_quote']);
    }

    public function test_field_count_mismatch_carries_a_locator(): void
    {
        $bytes = "A\tB\tC\n1\t2\t3\n4\t5\n6\t7\t8\t9\n";
        $rows = iterator_to_array($this->reader($bytes)->rows(), false);

        self::assertInstanceOf(EhiTsvRow::class, $rows[0]);
        self::assertInstanceOf(EhiTsvMalformedRow::class, $rows[1]);
        self::assertSame(EhiTsvMalformedRow::FIELD_COUNT_MISMATCH, $rows[1]->code);
        self::assertSame(3, $rows[1]->lineNumber);
        self::assertSame(strpos($bytes, "4\t5"), $rows[1]->byteOffset);
        self::assertSame(3, $rows[1]->expectedFields);
        self::assertSame(2, $rows[1]->actualFields);
        self::assertInstanceOf(EhiTsvMalformedRow::class, $rows[2]);
        self::assertSame(4, $rows[2]->actualFields);
        self::assertSame(4, $rows[2]->lineNumber);
    }

    public function test_rfc4180_quoted_fields_hold_tabs_newlines_and_doubled_quotes(): void
    {
        $bytes = "A\tB\tC\n\"x\ty\"\t\"line1\nline2\"\t\"say \"\"hi\"\"\"\nnext\t\"\"\tz\n";
        $reader = $this->reader($bytes, EhiDialect::provisional()->withQuoteMode(EhiDialect::QUOTE_RFC4180));
        $rows = $this->rows($reader);

        self::assertSame(["x\ty", "line1\nline2", 'say "hi"'], $rows[0]->values);
        self::assertSame(2, $rows[0]->lineNumber);
        self::assertSame(['next', '', 'z'], $rows[1]->values);
        // The embedded LF advances the physical line count.
        self::assertSame(4, $rows[1]->lineNumber);
        self::assertSame(strpos($bytes, 'next'), $rows[1]->byteOffset);
    }

    public function test_rfc4180_quoted_values_spanning_read_chunks(): void
    {
        $dialect = EhiDialect::provisional()->withQuoteMode(EhiDialect::QUOTE_RFC4180);
        $long = str_repeat("ab\"\"c\n", 25000);
        $bytes = "A\tB\n\"".$long."\"\t1\n\"".str_repeat("q\n", 90000)."\"\t2\nok\t3\n";
        $rows = iterator_to_array($this->reader($bytes, $dialect, $this->limits(rowBytes: 170000, valueBytes: 170000))->rows(), false);

        self::assertInstanceOf(EhiTsvRow::class, $rows[0]);
        self::assertSame([str_replace('""', '"', $long), '1'], $rows[0]->values);
        self::assertInstanceOf(EhiTsvMalformedRow::class, $rows[1]);
        self::assertSame(EhiTsvMalformedRow::ROW_TOO_LARGE, $rows[1]->code);
        self::assertSame(2 + 25000 + 1, $rows[1]->lineNumber);
        self::assertInstanceOf(EhiTsvRow::class, $rows[2]);
        self::assertSame(['ok', '3'], $rows[2]->values);
        self::assertSame(25003 + 90000 + 1, $rows[2]->lineNumber);
        self::assertSame(strrpos($bytes, 'ok'), $rows[2]->byteOffset);
    }

    public function test_rfc4180_stray_and_unterminated_quotes(): void
    {
        $dialect = EhiDialect::provisional()->withQuoteMode(EhiDialect::QUOTE_RFC4180);
        $rows = iterator_to_array($this->reader("A\tB\nab\"c\t1\n\"x\"y\t2\nok\t3\n\"open\t4\nmore\n", $dialect)->rows(), false);

        self::assertSame(EhiTsvMalformedRow::STRAY_QUOTE, $rows[0]->code);
        self::assertSame(EhiTsvMalformedRow::STRAY_QUOTE, $rows[1]->code);
        self::assertInstanceOf(EhiTsvRow::class, $rows[2]);
        self::assertSame(['ok', '3'], $rows[2]->values);
        self::assertInstanceOf(EhiTsvMalformedRow::class, $rows[3]);
        self::assertSame(EhiTsvMalformedRow::UNTERMINATED_QUOTE, $rows[3]->code);
        self::assertSame(5, $rows[3]->lineNumber);
        self::assertCount(4, $rows);
    }

    public function test_invalid_utf8_row_is_malformed_and_not_transcoded(): void
    {
        $reader = $this->reader("A\tB\n1\t\xC3\x28\n2\tok\n");
        $rows = iterator_to_array($reader->rows(), false);

        self::assertInstanceOf(EhiTsvMalformedRow::class, $rows[0]);
        self::assertSame(EhiTsvMalformedRow::INVALID_ENCODING, $rows[0]->code);
        self::assertSame(2, $rows[0]->lineNumber);
        self::assertInstanceOf(EhiTsvRow::class, $rows[1]);
        self::assertSame(1, $reader->counters()['malformed_invalid_encoding']);
    }

    public function test_identifiers_and_exact_decimals_stay_raw_strings(): void
    {
        $rows = $this->rows($this->reader("PAT_ID\tORD_DATE_REAL\tVAL\n000123\t65000.01\t1.50\n0\t65000.10\t-0\n"));

        self::assertSame(['000123', '65000.01', '1.50'], $rows[0]->values);
        self::assertSame(['0', '65000.10', '-0'], $rows[1]->values);
    }

    public function test_empty_cell_null_marker_and_missing_column_are_distinct(): void
    {
        $dialect = EhiDialect::provisional()->withNullMarker('NULL');
        $reader = $this->reader("A\tB\tC\n\tNULL\tnull\nx\ty\n", $dialect);
        $rows = iterator_to_array($reader->rows(), false);

        self::assertInstanceOf(EhiTsvRow::class, $rows[0]);
        self::assertSame(['', null, 'null'], $rows[0]->values);
        self::assertInstanceOf(EhiTsvMalformedRow::class, $rows[1]);
        self::assertSame(EhiTsvMalformedRow::FIELD_COUNT_MISMATCH, $rows[1]->code);
        self::assertSame(1, $reader->counters()['empty_cells']);
        self::assertSame(1, $reader->counters()['null_marker_cells']);
        self::assertNotSame(EhiDialect::provisional()->version, $dialect->version);

        // Without a marker the same spelling is just text.
        $plain = $this->rows($this->reader("A\tB\tC\n\tNULL\tnull\n"));
        self::assertSame(['', 'NULL', 'null'], $plain[0]->values);
    }

    public function test_quoted_null_marker_is_text_in_rfc4180(): void
    {
        $dialect = new EhiDialect(quoteMode: EhiDialect::QUOTE_RFC4180, nullMarker: 'NULL');
        $rows = $this->rows($this->reader("A\tB\nNULL\t\"NULL\"\n", $dialect));

        self::assertSame([null, 'NULL'], $rows[0]->values);
    }

    public function test_row_and_value_size_limits(): void
    {
        $limits = $this->limits(rowBytes: 64, valueBytes: 16);
        $bytes = "A\tB\n".str_repeat('x', 200)."\t1\n".str_repeat('y', 20)."\t2\nok\t3\n";
        $rows = iterator_to_array($this->reader($bytes, limits: $limits)->rows(), false);

        self::assertSame(EhiTsvMalformedRow::ROW_TOO_LARGE, $rows[0]->code);
        self::assertSame(2, $rows[0]->lineNumber);
        self::assertSame(4, $rows[0]->byteOffset);
        self::assertSame(EhiTsvMalformedRow::VALUE_TOO_LARGE, $rows[1]->code);
        self::assertSame(3, $rows[1]->lineNumber);
        self::assertInstanceOf(EhiTsvRow::class, $rows[2]);
        self::assertSame(4, $rows[2]->lineNumber);
        self::assertSame(['ok', '3'], $rows[2]->values);
    }

    public function test_oversized_record_spanning_many_chunks_is_skipped_without_buffering(): void
    {
        $stream = $this->stream("A\tB\n".str_repeat('z', 3 * 1024 * 1024)."\t1\nok\t2\n");
        $before = memory_get_usage();
        $reader = new EhiTsvReader($stream, EhiDialect::provisional(), $this->limits(rowBytes: 1024, valueBytes: 1024));
        $rows = iterator_to_array($reader->rows(), false);

        self::assertSame(EhiTsvMalformedRow::ROW_TOO_LARGE, $rows[0]->code);
        self::assertSame(['ok', '2'], $rows[1]->values);
        self::assertSame(3, $rows[1]->lineNumber);
        self::assertLessThan(1024 * 1024, memory_get_usage() - $before);
    }

    public function test_streams_a_large_table_with_bounded_memory(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ehi-tsv-');
        self::assertIsString($path);
        try {
            $out = fopen($path, 'wb');
            self::assertIsResource($out);
            fwrite($out, "PAT_ID\tLINE\tORD_DATE_REAL\tNOTE\n");
            $chunk = '';
            for ($i = 0; $i < 200000; $i++) {
                $chunk .= sprintf("%06d\t%d\t%d.%02d\tsynthetic row text %d\n", $i, $i % 7, 60000 + $i % 900, $i % 100, $i);
                if ($i % 5000 === 4999) {
                    fwrite($out, $chunk);
                    $chunk = '';
                }
            }
            fwrite($out, $chunk);
            fclose($out);
            unset($chunk);
            self::assertGreaterThan(8 * 1024 * 1024, (int) filesize($path));

            $stream = fopen($path, 'rb');
            self::assertIsResource($stream);
            $baseline = memory_get_usage();
            memory_reset_peak_usage();
            $reader = new EhiTsvReader($stream, EhiDialect::provisional(), $this->limits());
            $count = 0;
            $last = null;
            foreach ($reader->rows() as $row) {
                self::assertInstanceOf(EhiTsvRow::class, $row);
                $count++;
                $last = $row;
            }
            fclose($stream);

            self::assertSame(200000, $count);
            self::assertInstanceOf(EhiTsvRow::class, $last);
            self::assertSame('199999', $last->values[0]);
            self::assertSame(200001, $last->lineNumber);
            self::assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $baseline);
        } finally {
            @unlink($path);
        }
    }

    public function test_rows_iterate_once(): void
    {
        $reader = $this->reader("A\n1\n");
        iterator_to_array($reader->rows());

        $this->expectException(\LogicException::class);
        iterator_to_array($reader->rows());
    }

    public function test_dialect_rejects_unsupported_settings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EhiDialect(encoding: 'ISO-8859-1');
    }

    public function test_provisional_dialect_defaults(): void
    {
        $dialect = EhiDialect::provisional();

        self::assertSame("\t", $dialect->delimiter);
        self::assertSame(EhiDialect::QUOTE_NONE, $dialect->quoteMode);
        self::assertSame('UTF-8', $dialect->encoding);
        self::assertTrue($dialect->allowBom);
        self::assertNull($dialect->nullMarker);
        self::assertLessThanOrEqual(32, strlen($dialect->version));
        self::assertLessThanOrEqual(32, strlen($dialect->withQuoteMode(EhiDialect::QUOTE_RFC4180)->version));
        self::assertNotSame($dialect->version, $dialect->withQuoteMode(EhiDialect::QUOTE_RFC4180)->version);
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function reader(string $bytes, ?EhiDialect $dialect = null, ?EhiLimits $limits = null): EhiTsvReader
    {
        return new EhiTsvReader($this->stream($bytes), $dialect ?? EhiDialect::provisional(), $limits ?? $this->limits());
    }

    /** @return list<EhiTsvRow> */
    private function rows(EhiTsvReader $reader): array
    {
        $rows = [];
        foreach ($reader->rows() as $row) {
            self::assertInstanceOf(EhiTsvRow::class, $row);
            $rows[] = $row;
        }

        return $rows;
    }

    private function limits(int $rowBytes = 1024 * 1024, int $valueBytes = 512 * 1024): EhiLimits
    {
        return new EhiLimits(
            maxPackageBytes: PHP_INT_MAX,
            maxMemberBytes: PHP_INT_MAX,
            maxMembers: 1000,
            maxExpansionRatio: 100,
            maxPathDepth: 8,
            maxPathBytes: 256,
            maxRowBytes: $rowBytes,
            maxValueBytes: $valueBytes,
            maxDictionaryBytes: 1024 * 1024,
            maxFindingsPerMember: 100,
        );
    }
}
