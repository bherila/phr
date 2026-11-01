<?php

namespace Tests\Support;

use App\Models\PhrPatient;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrImportResult;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * Helpers for tests that import a synthetic C-CDA fixture from tests/Fixtures/ccda.
 *
 * @phpstan-require-extends TestCase
 */
trait ImportsCcdaFixtures
{
    protected function ccdaFixture(string $name): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/ccda/'.$name));
        $this->assertIsString($xml);

        return $xml;
    }

    protected function importCcda(string $xml, PhrPatient $patient): PhrImportResult
    {
        return app(PhrCcdaImporter::class)->importXml($xml, $patient, $patient->owner_user_id);
    }

    /** A synthetic document holding one section, given as its inner XML. */
    protected function ccdaSectionDocument(string $section): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
              <component><structuredBody><component><section>
              {$section}
              </section></component></structuredBody></component>
            </ClinicalDocument>
            XML;
    }

    /** The same document as the narrative-table reader saw it: no structured entries. */
    protected function withoutEntries(string $xml): string
    {
        [$document, $xpath] = $this->ccdaDom($xml);
        foreach (iterator_to_array($xpath->query('//*[local-name()="entry"]') ?: []) as $entry) {
            $entry->parentNode?->removeChild($entry);
        }

        return (string) $document->saveXML();
    }

    /** @return array{0: DOMDocument, 1: DOMXPath} */
    protected function ccdaDom(string $xml): array
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml));

        return [$document, new DOMXPath($document)];
    }

    protected function ccdaPatient(): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $this->createUser()->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);
    }

    /** decimal(18,10) columns read back padded; compare the number itself. */
    protected function ccdaDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = rtrim(rtrim((string) $value, '0'), '.');

        return $string === '' ? '0' : $string;
    }
}
