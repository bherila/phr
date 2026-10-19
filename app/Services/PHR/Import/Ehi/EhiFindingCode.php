<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiFinding;

/**
 * Fixed finding codes written by intake accounting. A finding carries one of these,
 * a locator, and fixed-code details; never a cell value. Column names appear only
 * in `column_name`, and only for unknown_column.
 */
final class EhiFindingCode
{
    /** The package's dictionary documents no tables (unrecognized or absent). */
    public const string DICTIONARY_EMPTY = 'dictionary_empty';

    /** A dictionary member exceeded the dictionary size limit and was not parsed. */
    public const string DICTIONARY_OVERSIZE = 'dictionary_oversize';

    /** A table member the dictionary does not document. */
    public const string UNKNOWN_TABLE = 'unknown_table';

    /** A header column the dictionary does not document for that table. */
    public const string UNKNOWN_COLUMN = 'unknown_column';

    /** A table member with no usable header; its rows cannot be counted. */
    public const string MALFORMED_HEADER = 'malformed_header';

    /** A data record the dialect rejects; detail.code is an EhiTsvMalformedRow code. */
    public const string MALFORMED_ROW = 'malformed_row';

    /** A member classified as neither table, documentation, note, nor media. */
    public const string UNKNOWN_MEMBER = 'unknown_member';

    /** Further findings for one member were dropped; detail.omitted counts them. */
    public const string FINDINGS_TRUNCATED = 'findings_truncated';

    public const array SEVERITY = [
        self::DICTIONARY_EMPTY => PhrEhiFinding::SEVERITY_WARNING,
        self::DICTIONARY_OVERSIZE => PhrEhiFinding::SEVERITY_WARNING,
        self::UNKNOWN_TABLE => PhrEhiFinding::SEVERITY_INFO,
        self::UNKNOWN_COLUMN => PhrEhiFinding::SEVERITY_INFO,
        self::MALFORMED_HEADER => PhrEhiFinding::SEVERITY_ERROR,
        self::MALFORMED_ROW => PhrEhiFinding::SEVERITY_WARNING,
        self::UNKNOWN_MEMBER => PhrEhiFinding::SEVERITY_INFO,
        self::FINDINGS_TRUNCATED => PhrEhiFinding::SEVERITY_INFO,
    ];

    public const array ALL = [
        self::DICTIONARY_EMPTY,
        self::DICTIONARY_OVERSIZE,
        self::UNKNOWN_TABLE,
        self::UNKNOWN_COLUMN,
        self::MALFORMED_HEADER,
        self::MALFORMED_ROW,
        self::UNKNOWN_MEMBER,
        self::FINDINGS_TRUNCATED,
    ];
}
