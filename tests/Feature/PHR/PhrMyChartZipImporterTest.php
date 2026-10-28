<?php

namespace Tests\Feature\PHR;

use App\Models\PhrCondition;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Models\User;
use App\Services\PHR\Import\PhrMyChartZipImporter;
use Tests\Support\PhrCcdaSyntheticDocument;
use Tests\TestCase;
use ZipArchive;

/** A MyChart record ZIP: members are read by what they are, not by their names. */
class PhrMyChartZipImporterTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_members_are_routed_by_content_and_non_clinical_json_is_ignored(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $zip = $this->zip([
            // A C-CDA document with a .json name, and a FHIR bundle with a .xml name.
            'Records/summary.json' => "\xEF\xBB\xBF".PhrCcdaSyntheticDocument::xml(),
            'Records/bundle.xml' => "\n".(string) json_encode(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
                ['resource' => ['resourceType' => 'Condition', 'id' => 'condition-zip', 'code' => ['text' => 'Synthetic zip condition']]],
            ]]),
            'metadata.json' => '{"exportedAt":"2026-10-01"}',
        ]);

        $importer = app(PhrMyChartZipImporter::class);
        self::assertTrue($importer->recognizes($zip));
        $result = $importer->importFile($zip, $patient, $owner->id);

        self::assertSame(0, $result->skipped, implode(' | ', $result->warnings));
        self::assertSame([], $result->warnings);
        self::assertGreaterThan(0, PhrImmunization::query()->where('patient_id', $patient->id)->count(), 'the C-CDA was imported');
        self::assertSame(1, PhrCondition::query()->where('patient_id', $patient->id)->where('name', 'Synthetic zip condition')->count(), 'the FHIR bundle was imported');
    }

    /** @param  array<string, string>  $members */
    private function zip(array $members): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phr-mychart-').'.zip';
        $this->paths[] = $path;
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($members as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();

        return [$owner, PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient', 'relationship' => 'self'])];
    }
}
