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

    /** @return self::FHIR|self::CCDA|null */
    public static function detect(string $contents): ?string
    {
        $trimmed = ltrim($contents, "\xEF\xBB\xBF \t\r\n");
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
