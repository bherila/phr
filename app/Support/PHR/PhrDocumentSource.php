<?php

namespace App\Support\PHR;

use App\Models\PhrDocument;

/**
 * The rule for `phr_documents.source`: every stored value is one of
 * PhrDocument::SOURCES.
 *
 * The column is a free `string(80)`, but the browser parses it against that
 * list, so any other value makes the document unreadable there. PhrDocument
 * enforces the rule on save (assertValid). Importers and native-backup restore,
 * which read values from outside, map them into the list first (normalize).
 */
final class PhrDocumentSource
{
    /**
     * Values earlier code or migrated data used, and the allowed source each
     * means. Anything else outside the list is a manual upload.
     *
     * @var array<string, string>
     */
    public const array LEGACY_ALIASES = [
        'genai' => 'genai_import',
        'fhir' => 'fhir_import',
        'ccda' => 'ccda_import',
        'mychart' => 'mychart_zip',
        'cli' => 'manual_upload',
        'cli_pdf' => 'manual_upload',
        'onedrive_manual_review' => 'manual_upload',
        'outlook_email_attachment' => 'manual_upload',
    ];

    public const string FALLBACK = 'manual_upload';

    public static function isValid(mixed $source): bool
    {
        return is_string($source) && in_array($source, PhrDocument::SOURCES, true);
    }

    /** Map a value read from outside (an import payload, an archive) into the list. */
    public static function normalize(mixed $source): string
    {
        if (self::isValid($source)) {
            return $source;
        }
        // Compared case-insensitively, as a MySQL _ci collation already would.
        $value = is_string($source) ? strtolower(trim($source)) : '';
        if (self::isValid($value)) {
            return $value;
        }

        return self::LEGACY_ALIASES[$value] ?? self::FALLBACK;
    }

    /** @throws InvalidPhrDocumentSource */
    public static function assertValid(mixed $source): void
    {
        if (! self::isValid($source)) {
            throw InvalidPhrDocumentSource::for($source);
        }
    }
}
