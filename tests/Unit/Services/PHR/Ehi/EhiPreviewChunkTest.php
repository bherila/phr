<?php

namespace Tests\Unit\Services\PHR\Ehi;

use App\Services\PHR\Import\Ehi\EhiPreviewChunk;
use JsonException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EhiPreviewChunkTest extends TestCase
{
    /** @return array<string, array{list<int|string>}> */
    public static function keys(): array
    {
        return [
            'list then object' => [[0, '1', '2', '00', '-1', '1e2']],
            'numeric string normalization and replacements' => [['0', 0, '1', 1, '01', '+1', '-1', -1]],
            'object then contiguous integer keys stays object' => [[1, 0, 2, '1', '0']],
            'escaped and non-ascii object keys' => [["a\"/\\\n", 'é/雪', '', 'é/雪']],
            'large numeric keys' => [[(string) PHP_INT_MAX, PHP_INT_MAX, (string) PHP_INT_MAX.'0']],
        ];
    }

    /** @param list<int|string> $keys */
    #[DataProvider('keys')]
    public function test_every_append_and_replacement_matches_default_json_encoding(array $keys): void
    {
        $chunk = new EhiPreviewChunk;
        $rows = [];
        self::assertSame(2, $chunk->bytes());
        foreach ($keys as $index => $key) {
            $row = ['attributes' => ['unicode' => 'é/雪', 'escaped' => "\"\\\n\t/", 'values' => [null, true, false, 1.0, -0.0, $index]], 'revisions' => [$index]];
            $rows[$key] = $row;
            $chunk->put($key, $row);
            self::assertSame($rows, $chunk->rows());
            self::assertSame(count($rows), $chunk->count());
            self::assertSame(strlen(json_encode($rows, JSON_THROW_ON_ERROR)), $chunk->bytes());
            self::assertSame(strlen(json_encode($rows, JSON_THROW_ON_ERROR)), $chunk->bytes());
        }
    }

    public function test_multiple_pending_appends_and_overwrites_are_charged_once(): void
    {
        $chunk = new EhiPreviewChunk;
        $rows = [];
        foreach (['0', '1', '00', '0', '1', '00'] as $index => $key) {
            $rows[$key] = ['value' => str_repeat('é/', $index)];
            $chunk->put($key, $rows[$key]);
        }
        self::assertSame(strlen(json_encode($rows, JSON_THROW_ON_ERROR)), $chunk->bytes());
        $chunk->clear();
        self::assertSame([], $chunk->rows());
        self::assertSame(0, $chunk->count());
        self::assertSame(2, $chunk->bytes());
        $chunk->put('0', ['after' => 'reset']);
        self::assertSame(strlen(json_encode([['after' => 'reset']], JSON_THROW_ON_ERROR)), $chunk->bytes());
    }

    public function test_mutable_objects_and_references_are_reencoded_after_changes(): void
    {
        $chunk = new EhiPreviewChunk;
        $object = (object) ['value' => 'a'];
        $referenced = 'short';
        $row = ['object' => $object, 'nested' => ['value' => &$referenced]];
        $chunk->put('first', $row);
        self::assertSame(strlen(json_encode(['first' => $row], JSON_THROW_ON_ERROR)), $chunk->bytes());
        $object->value = 'é/雪 longer';
        $referenced = str_repeat('x', 100);
        $chunk->put('second', ['value' => null]);
        self::assertSame(strlen(json_encode(['first' => $row, 'second' => ['value' => null]], JSON_THROW_ON_ERROR)), $chunk->bytes());
        $referenced = 'changed without another append';
        self::assertSame(strlen(json_encode($chunk->rows(), JSON_THROW_ON_ERROR)), $chunk->bytes());
    }

    public function test_a_reference_without_an_object_also_cannot_be_cached(): void
    {
        $chunk = new EhiPreviewChunk;
        $value = 'short';
        $chunk->put(0, ['nested' => ['value' => &$value]]);
        self::assertSame(strlen(json_encode($chunk->rows(), JSON_THROW_ON_ERROR)), $chunk->bytes());
        $value = ['new' => 'é/雪'];
        self::assertSame(strlen(json_encode($chunk->rows(), JSON_THROW_ON_ERROR)), $chunk->bytes());
    }

    public function test_serializable_values_are_only_called_by_bytes_and_count_flush_short_circuits_it(): void
    {
        $value = new class implements JsonSerializable
        {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                return ++$this->calls;
            }
        };
        $chunk = new EhiPreviewChunk;
        for ($index = 0; $index < 199; $index++) {
            $chunk->put($index, ['value' => $index]);
            self::assertFalse($chunk->count() >= 200 || $chunk->bytes() >= 1024 * 1024);
        }
        $chunk->put(199, ['value' => $value]);
        self::assertTrue($chunk->count() >= 200 || $chunk->bytes() >= 1024 * 1024);
        self::assertSame(0, $value->calls);
        $chunk->clear();
        $chunk->put('first', ['value' => $value]);
        self::assertSame(0, $value->calls);
        self::assertSame(strlen('{"first":{"value":1}}'), $chunk->bytes());
        self::assertSame(1, $value->calls);
        self::assertSame(strlen('{"first":{"value":2}}'), $chunk->bytes());
        self::assertSame(2, $value->calls);
        $chunk->clear();
        $chunk->put(0, ['value' => 'plain again']);
        self::assertSame(strlen('[{"value":"plain again"}]'), $chunk->bytes());
        self::assertSame(2, $value->calls);
    }

    public function test_byte_flush_boundary_is_exact_at_one_mebibyte(): void
    {
        foreach ([-1, 0, 1] as $delta) {
            $chunk = new EhiPreviewChunk;
            $chunk->put('é/"', ['value' => '']);
            $overhead = $chunk->bytes();
            $chunk->put('é/"', ['value' => str_repeat('x', 1024 * 1024 - $overhead + $delta)]);
            self::assertSame(1024 * 1024 + $delta, $chunk->bytes());
            self::assertSame($delta >= 0, $chunk->count() >= 200 || $chunk->bytes() >= 1024 * 1024);
        }
    }

    public function test_multiple_byte_flushes_match_whole_chunk_encoding_for_escaped_payloads(): void
    {
        $chunk = new EhiPreviewChunk;
        $rows = [];
        $flushed = 0;
        for ($index = 0; $index < 300; $index++) {
            $key = $index < 2 ? (string) $index : 'subject/é/'.$index;
            $row = ['proposed' => ['text' => str_repeat('é/"雪', 500 + $index % 7)]];
            $rows[$key] = $row;
            $chunk->put($key, $row);
            self::assertSame(strlen(json_encode($rows, JSON_THROW_ON_ERROR)), $chunk->bytes());
            $oldFlush = count($rows) >= 200 || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) >= 1024 * 1024;
            self::assertSame($oldFlush, $chunk->count() >= 200 || $chunk->bytes() >= 1024 * 1024);
            if ($oldFlush) {
                self::assertSame($rows, $chunk->rows());
                $flushed++;
                $rows = [];
                $chunk->clear();
            }
        }
        self::assertGreaterThan(1, $flushed);
    }

    public function test_default_depth_limit_and_recursive_reference_errors_are_preserved(): void
    {
        foreach ([510, 511, 512] as $depth) {
            $row = [];
            for ($index = 1; $index < $depth; $index++) {
                $row = [$row];
            }
            $chunk = new EhiPreviewChunk;
            $chunk->put('key', $row);
            try {
                $expected = strlen(json_encode(['key' => $row], JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                try {
                    $chunk->bytes();
                    self::fail('The original depth exception must be preserved.');
                } catch (JsonException $actual) {
                    self::assertSame($exception->getCode(), $actual->getCode());
                }

                continue;
            }
            self::assertSame($expected, $chunk->bytes());
        }
        $recursive = [];
        $recursive['self'] = &$recursive;
        $chunk = new EhiPreviewChunk;
        $chunk->put('recursive', $recursive);
        $this->expectException(JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_RECURSION);
        $chunk->bytes();
    }

    public function test_invalid_utf8_and_serialization_exceptions_are_not_suppressed(): void
    {
        $chunk = new EhiPreviewChunk;
        $chunk->put('invalid', ['value' => "\xFF"]);
        try {
            $chunk->bytes();
            self::fail('Invalid UTF-8 must still be refused.');
        } catch (JsonException $exception) {
            self::assertSame(JSON_ERROR_UTF8, $exception->getCode());
        }
        $chunk->clear();
        $chunk->put('throw', ['value' => new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new JsonException('mapping serialization failed');
            }
        }]);
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('mapping serialization failed');
        $chunk->bytes();
    }
}
