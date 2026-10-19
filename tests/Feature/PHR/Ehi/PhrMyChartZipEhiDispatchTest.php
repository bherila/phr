<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrCondition;
use App\Models\PhrPatient;
use App\Services\PHR\Import\Ehi\EhiExportNotSupportedHere;
use App\Services\PHR\Import\PhrMyChartZipImporter;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;
use ZipArchive;

class PhrMyChartZipEhiDispatchTest extends TestCase
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

    public function test_ehi_tables_export_is_refused_and_nothing_is_imported(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner->id);
        $zip = EhiSyntheticPackage::standard()
            ->with('fhir/bundle.json', $this->bundle())
            ->writeZip($this->path());

        try {
            app(PhrMyChartZipImporter::class)->importFile($zip, $patient, $owner->id);
            self::fail('An EHI Tables export must not import as an empty success.');
        } catch (EhiExportNotSupportedHere $refused) {
            self::assertSame('Recognized an Epic EHI Tables export; use phr:ehi:intake.', $refused->getMessage());
        }

        self::assertSame(0, PhrCondition::query()->count(), 'nothing is imported from a refused export');
    }

    public function test_fhir_only_zip_still_imports(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner->id);
        $path = $this->path();
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('records/bundle.json', $this->bundle());
        $zip->addFromString('records/README.txt', 'Synthetic readme');
        $zip->close();

        $result = app(PhrMyChartZipImporter::class)->importFile($path, $patient, $owner->id);

        self::assertSame(1, $result->created);
        self::assertSame('Synthetic condition', PhrCondition::query()->where('patient_id', $patient->id)->sole()->name);
    }

    public function test_fhir_zip_with_unrelated_tsv_and_html_attachments_still_imports(): void
    {
        $owner = $this->createUser();
        $patient = $this->patient($owner->id);
        $path = $this->path();
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('records/bundle.json', $this->bundle());
        $zip->addFromString('attachments/readings.tsv', "SYNTH_A\tSYNTH_B\n1\t2\n");
        $zip->addFromString('attachments/summary.tsv', "SYNTH_C\tSYNTH_D\n3\t4\n");
        $zip->addFromString('attachments/letter.html', '<html><body>Synthetic letter</body></html>');
        // Named like an EHI table but with no header: a name alone is not structure.
        $zip->addFromString('attachments/PATIENT.txt', 'Synthetic note text');
        $zip->close();

        $result = app(PhrMyChartZipImporter::class)->importFile($path, $patient, $owner->id);

        self::assertSame(1, $result->created);
        self::assertSame('Synthetic condition', PhrCondition::query()->where('patient_id', $patient->id)->sole()->name);
    }

    private function patient(int $ownerId): PhrPatient
    {
        return PhrPatient::query()->create(['owner_user_id' => $ownerId, 'display_name' => 'Synthetic Patient', 'relationship' => 'self']);
    }

    private function bundle(): string
    {
        return json_encode([
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'entry' => [[
                'resource' => [
                    'resourceType' => 'Condition',
                    'id' => 'condition-1',
                    'code' => ['text' => 'Synthetic condition'],
                    'clinicalStatus' => ['text' => 'active'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function path(): string
    {
        $path = sys_get_temp_dir().'/mychart-'.bin2hex(random_bytes(8)).'.zip';
        $this->paths[] = $path;

        return $path;
    }
}
