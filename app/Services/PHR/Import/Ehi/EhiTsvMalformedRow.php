<?php

namespace App\Services\PHR\Import\Ehi;

/**
 * A record the dialect cannot accept. It carries a fixed code and a locator only,
 * never the record's bytes; the original bytes stay in the preserved member.
 */
final readonly class EhiTsvMalformedRow
{
    public const string FIELD_COUNT_MISMATCH = 'field_count_mismatch';

    public const string INVALID_ENCODING = 'invalid_encoding';

    public const string ROW_TOO_LARGE = 'row_too_large';

    public const string VALUE_TOO_LARGE = 'value_too_large';

    public const string UNTERMINATED_QUOTE = 'unterminated_quote';

    public const string STRAY_QUOTE = 'stray_quote';

    public const array CODES = [
        self::FIELD_COUNT_MISMATCH,
        self::INVALID_ENCODING,
        self::ROW_TOO_LARGE,
        self::VALUE_TOO_LARGE,
        self::UNTERMINATED_QUOTE,
        self::STRAY_QUOTE,
    ];

    public function __construct(
        public int $lineNumber,
        public int $byteOffset,
        public string $code,
        /** Header field count; set for field_count_mismatch. */
        public ?int $expectedFields = null,
        /** Fields found in the record; set for field_count_mismatch. */
        public ?int $actualFields = null,
    ) {}
}
