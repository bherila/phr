<?php

namespace App\Services\PHR\Import;

use DOMDocument;
use DOMXPath;

/**
 * How many people a structured file's records are about, judged by birth date.
 *
 * A file imported directly must not carry another person's records into the
 * chosen patient: a MyChart ZIP can hold a dependent's export beside the
 * account holder's. Record identifiers cannot decide this, because one person's
 * documents from different organizations carry different MRNs; names vary in
 * spelling, suffixes and null flavors. A birth date is stable across sources,
 * and a partial date (a year, or a year and month) is taken to match a full one
 * it begins. Twins share one and are not told apart.
 */
final class PhrSourcePatients
{
    /** @var array<string, true> */
    private array $birthDates = [];

    /** @param  array<string, mixed>  $bundle */
    public function addFhirBundle(array $bundle): void
    {
        foreach (is_array($bundle['entry'] ?? null) ? $bundle['entry'] : [] as $entry) {
            $resource = is_array($entry) ? ($entry['resource'] ?? null) : null;
            if (is_array($resource) && ($resource['resourceType'] ?? null) === 'Patient' && is_string($resource['birthDate'] ?? null)) {
                $this->add(substr($resource['birthDate'], 0, 10));
            }
        }
    }

    public function addCcda(string $xml): void
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                return;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('v3', 'urn:hl7-org:v3');
        $times = $xpath->query('//v3:recordTarget/v3:patientRole/v3:patient/v3:birthTime/@value');
        foreach ($times === false ? [] : $times as $time) {
            $digits = (string) preg_replace('/\D/', '', (string) $time->nodeValue);
            if (strlen($digits) >= 4) {
                $this->add(implode('-', array_filter([substr($digits, 0, 4), substr($digits, 4, 2), substr($digits, 6, 2)], static fn (string $part): bool => $part !== '')));
            }
        }
    }

    /** Whether the records name more than one person. */
    public function multiple(): bool
    {
        $dates = array_keys($this->birthDates);
        foreach ($dates as $left) {
            foreach ($dates as $right) {
                if (! str_starts_with($left, $right) && ! str_starts_with($right, $left)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function add(string $date): void
    {
        if (preg_match('/\A\d{4}(-\d{2}){0,2}\z/', $date) === 1) {
            $this->birthDates[$date] = true;
        }
    }
}
