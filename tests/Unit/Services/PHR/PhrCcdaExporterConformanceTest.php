<?php

namespace Tests\Unit\Services\PHR;

use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;

class PhrCcdaExporterConformanceTest extends TestCase
{
    public function test_synthetic_conformance_document_exercises_the_exporter_contract(): void
    {
        $xml = PhrCcdaSyntheticDocument::xml();

        $this->assertStringContainsString('root="00000000-0000-4000-8000-000000000001"', $xml);
        $this->assertStringContainsString('extension="2024-05-01"', $xml);
        $this->assertStringContainsString('<td>Synthetic analyte</td>', $xml);
        $this->assertStringContainsString('<td>Synthetic vital</td>', $xml);
        $this->assertStringContainsString('<td>Synthetic condition</td>', $xml);
        $this->assertStringContainsString('<td>Synthetic medication</td>', $xml);
        $this->assertStringContainsString('<td>Synthetic substance</td>', $xml);
        $this->assertStringContainsString('<td>Synthetic procedure</td>', $xml);
        $this->assertStringContainsString('<td>Synthetic vaccine</td>', $xml);
        $this->assertStringContainsString('<td>Synthetic visit</td>', $xml);
        $this->assertStringNotContainsString('urn:phr:patient', $xml);
    }
}
