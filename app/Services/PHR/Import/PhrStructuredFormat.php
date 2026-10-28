<?php

namespace App\Services\PHR\Import;

use DOMDocument;

/**
 * Recognizes a single structured clinical file by its content, never its name,
 * and without importing anything: a FHIR R4 Bundle, or an HL7 C-CDA
 * ClinicalDocument.
 */
final class PhrStructuredFormat
{
    public const string FHIR = 'fhir';

    public const string CCDA = 'ccda';

    /**
     * The text as the importers must read it: without a UTF-8 byte order mark or
     * leading whitespace, either of which a JSON decoder or an XML declaration
     * refuses. Detect and import the same normalized text.
     */
    public static function normalize(string $contents): string
    {
        return ltrim($contents, "\xEF\xBB\xBF \t\r\n");
    }

    /** @return self::FHIR|self::CCDA|null */
    public static function detect(string $contents): ?string
    {
        $trimmed = self::normalize($contents);
        if ($trimmed === '') {
            return null;
        }
        if ($trimmed[0] === '{') {
            return self::fhirBundle($trimmed) !== null ? self::FHIR : null;
        }
        if ($trimmed[0] !== '<') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($trimmed, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $document->documentElement?->localName === 'ClinicalDocument'
            && $document->documentElement->namespaceURI === 'urn:hl7-org:v3' ? self::CCDA : null;
    }

    /**
     * The decoded bundle, when the text is a FHIR Bundle.
     *
     * @return array<string, mixed>|null
     */
    public static function fhirBundle(string $contents): ?array
    {
        $bundle = json_decode($contents, true);

        return is_array($bundle) && ($bundle['resourceType'] ?? null) === 'Bundle'
            && (! isset($bundle['entry']) || is_array($bundle['entry'])) ? $bundle : null;
    }
}
