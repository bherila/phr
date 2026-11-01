<?php

namespace App\Support\PHR;

use App\Models\PhrDocument;

/**
 * The rule for `phr_documents.document_type`: every stored value is one of
 * PhrDocument::DOCUMENT_TYPES.
 *
 * The column is a free `string(80)`. Request input is validated against the
 * list, and importers map what they read into it, but nothing below them held
 * a writer to it. PhrDocument enforces the rule on save (assertValid).
 * Importers and native-backup restore, which read values from outside, map
 * them into the list first (normalize).
 */
final class PhrDocumentType
{
    /**
     * Values earlier code wrote, and the allowed type each means. Anything else
     * outside the list is `other`.
     *
     * @var array<string, string>
     */
    public const array LEGACY_ALIASES = [
        // The column's original default.
        'general' => 'other',
        // The FHIR DocumentReference importer's fallback literal.
        'document_reference' => 'other',
    ];

    public const string FALLBACK = 'other';

    public static function isValid(mixed $type): bool
    {
        return is_string($type) && in_array($type, PhrDocument::DOCUMENT_TYPES, true);
    }

    /** Map a value read from outside (an import payload, an archive) into the list. */
    public static function normalize(mixed $type): string
    {
        if (self::isValid($type)) {
            return $type;
        }
        // Compared case-insensitively, as a MySQL _ci collation already would,
        // and with spaces or hyphens read as underscores ("Lab Report").
        $value = is_string($type) ? (string) preg_replace('/[\s-]+/', '_', strtolower(trim($type))) : '';
        if (self::isValid($value)) {
            return $value;
        }

        return self::LEGACY_ALIASES[$value] ?? self::FALLBACK;
    }

    /** @throws InvalidPhrDocumentType */
    public static function assertValid(mixed $type): void
    {
        if (! self::isValid($type)) {
            throw InvalidPhrDocumentType::for($type);
        }
    }
}
