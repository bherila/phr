<?php

namespace App\Services\PHR\Import\Ehi;

use App\Models\PhrEhiFinding;

/**
 * Fixed finding codes written by intake accounting, projection, and preview. A finding carries one of these,
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

    /** A table member with no usable header; each later record is a header_unusable malformed_row. */
    public const string MALFORMED_HEADER = 'malformed_header';

    /** A data record the dialect rejects; detail.code is an EhiTsvMalformedRow code. */
    public const string MALFORMED_ROW = 'malformed_row';

    /** A member classified as neither table, documentation, note, nor media. */
    public const string UNKNOWN_MEMBER = 'unknown_member';

    /**
     * A `.tsv`-shaped member whose name is not an ASCII identifier, so it is kept
     * as an unknown member rather than accounted as a table. Never names it.
     */
    public const string UNSUPPORTED_TABLE_NAME = 'unsupported_table_name';

    /** Further findings for one member were dropped; detail.omitted counts them. */
    public const string FINDINGS_TRUNCATED = 'findings_truncated';

    /**
     * A mapped table whose dictionary primary key is absent or differs from the
     * key the mapping expects (or whose header lacks a key column). The table is
     * not projected; detail.mapping and detail.problem say which and why.
     */
    public const string MAPPING_KEY_MISMATCH = 'mapping_key_mismatch';

    /** A mapped row with an empty or null primary-key cell; quarantined, no entity. */
    public const string MISSING_KEY = 'missing_key';

    /**
     * A mapped row whose values are too large for one native backup record (a
     * quarter of the restore reader's limit); quarantined, no entity, so every
     * revision that is backed up can also be restored. Its bytes stay preserved.
     */
    public const string REVISION_TOO_LARGE = 'revision_too_large';

    /** A mapped row whose full primary key repeats within one member; every such occurrence is quarantined. */
    public const string DUPLICATE_KEY = 'duplicate_key';

    public const array SEVERITY = [
        self::DICTIONARY_EMPTY => PhrEhiFinding::SEVERITY_WARNING,
        self::DICTIONARY_OVERSIZE => PhrEhiFinding::SEVERITY_WARNING,
        self::UNKNOWN_TABLE => PhrEhiFinding::SEVERITY_INFO,
        self::UNKNOWN_COLUMN => PhrEhiFinding::SEVERITY_INFO,
        self::MALFORMED_HEADER => PhrEhiFinding::SEVERITY_ERROR,
        self::MALFORMED_ROW => PhrEhiFinding::SEVERITY_WARNING,
        self::UNKNOWN_MEMBER => PhrEhiFinding::SEVERITY_INFO,
        self::UNSUPPORTED_TABLE_NAME => PhrEhiFinding::SEVERITY_WARNING,
        self::FINDINGS_TRUNCATED => PhrEhiFinding::SEVERITY_INFO,
        self::MAPPING_KEY_MISMATCH => PhrEhiFinding::SEVERITY_ERROR,
        self::MISSING_KEY => PhrEhiFinding::SEVERITY_WARNING,
        self::REVISION_TOO_LARGE => PhrEhiFinding::SEVERITY_WARNING,
        self::DUPLICATE_KEY => PhrEhiFinding::SEVERITY_WARNING,
    ];

    public const array ALL = [
        self::DICTIONARY_EMPTY,
        self::DICTIONARY_OVERSIZE,
        self::UNKNOWN_TABLE,
        self::UNKNOWN_COLUMN,
        self::MALFORMED_HEADER,
        self::MALFORMED_ROW,
        self::UNKNOWN_MEMBER,
        self::UNSUPPORTED_TABLE_NAME,
        self::FINDINGS_TRUNCATED,
        self::MAPPING_KEY_MISMATCH,
        self::MISSING_KEY,
        self::REVISION_TOO_LARGE,
        self::DUPLICATE_KEY,
    ];
}
