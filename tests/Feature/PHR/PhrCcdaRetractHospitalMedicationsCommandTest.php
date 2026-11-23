<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrPatient;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportsCcdaFixtures;
use Tests\Support\PhrCcdaNarrativeHospitalList;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;
use ZipArchive;

/**
 * phr:ccda:retract-hospital-medications: for documents nobody will import again,
 * the medication rows earlier imports made of their hospital medication lists
 * (#406) are found by reading the stored document again, reported in a dry run,
 * and retracted once.
 */
final class PhrCcdaRetractHospitalMedicationsCommandTest extends TestCase
{
    use ImportsCcdaFixtures;
    use UsesObjectStoreDisks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useObjectStoreDisks();
    }

    protected function tearDown(): void
    {
        $this->tearDownObjectStoreDisks();
        parent::tearDown();
    }

    public function test_a_dry_run_reports_the_rows_and_a_real_run_retracts_them_once(): void
    {
        $patient = $this->ccdaPatient();
        $document = $this->storedDocument($patient, 'discharge-summary.xml', $this->ccdaFixture('discharge-summary.xml'));
        $home = $this->row($patient, '2.25.4061|home-lisinopril', $document->id);
        $misrouted = [
            $this->row($patient, '2.25.4062|admit-lisinopril', $document->id),
            $this->row($patient, '2.25.4063|given-ceftriaxone', $document->id),
            $this->row($patient, '2.25.4064|discharge-cefpodoxime', $document->id),
        ];
        $confirmed = $this->row($patient, '2.25.4064|discharge-metformin', $document->id, PhrReviewStatus::CONFIRMED);
        $ids = implode(', #', array_map(static fn (PhrMedication $row): int => $row->id, $misrouted));

        $this->artisan('phr:ccda:retract-hospital-medications', ['--dry-run' => true])
            ->expectsOutputToContain("[dry run] Patient #{$patient->id}: would retract 3 medication row(s) read from hospital medication lists (#{$ids}).")
            ->expectsOutputToContain("[dry run] Patient #{$patient->id}: left 1 medication row(s) from hospital medication lists that a person confirmed (#{$confirmed->id})")
            ->assertSuccessful();
        $this->assertSame(0, PhrMedication::query()->whereNotNull('retracted_at')->count(), 'a dry run writes nothing');

        $this->artisan('phr:ccda:retract-hospital-medications')
            ->expectsOutputToContain("Patient #{$patient->id}: retracted 3 medication row(s) read from hospital medication lists (#{$ids}).")
            ->assertSuccessful();
        foreach ($misrouted as $row) {
            $this->assertNotNull($row->refresh()->retracted_at);
            $this->assertSame(PhrReviewStatus::PENDING, $row->review_status, 'retracted, not rejected');
        }
        $this->assertNull($home->refresh()->retracted_at);
        $this->assertNull($confirmed->refresh()->retracted_at);
        $retractedAt = array_map(static fn (PhrMedication $row): string => (string) $row->retracted_at, $misrouted);

        $this->artisan('phr:ccda:retract-hospital-medications')
            ->expectsOutputToContain('retracted 0 medication row(s) read from hospital medication lists')
            ->doesntExpectOutputToContain('retracted 3')
            ->assertSuccessful();
        $this->assertSame($retractedAt, array_map(static fn (PhrMedication $row): string => (string) $row->refresh()->retracted_at, $misrouted));
    }

    public function test_an_id_another_stored_document_lists_as_a_home_medication_is_kept(): void
    {
        $patient = $this->ccdaPatient();
        $discharge = $this->storedDocument($patient, 'discharge-summary.xml', $this->ccdaFixture('discharge-summary.xml'));
        // A later summary, stored as a MyChart ZIP, lists the discharge metformin as a home medication.
        $summary = $this->storedDocument($patient, 'mychart.zip', $this->zip(['summary.xml' => $this->homeList('2.25.4064', 'discharge-metformin')]));
        // The discharge summary wrote the metformin row first, so the row links to
        // it, and no row links to the later summary at all.
        $metformin = $this->row($patient, '2.25.4064|discharge-metformin', $discharge->id);
        $cefpodoxime = $this->row($patient, '2.25.4064|discharge-cefpodoxime', $discharge->id);
        $this->assertSame(0, PhrMedication::query()->where('source_document_id', $summary->id)->count());
        // A PDF is told apart by its first bytes and holds no C-CDA.
        $this->storedDocument($patient, 'letter.pdf', "%PDF-1.7\nSynthetic letter");

        $this->artisan('phr:ccda:retract-hospital-medications')->assertSuccessful();

        $this->assertNull($metformin->refresh()->retracted_at);
        $this->assertNotNull($cefpodoxime->refresh()->retracted_at);
    }

    public function test_rows_without_a_readable_stored_document_are_reported_not_guessed(): void
    {
        $patient = $this->ccdaPatient();
        $unlinked = $this->row($patient, '2.25.4062|admit-lisinopril', null);
        $missing = $this->storedDocument($patient, 'gone.xml', $this->ccdaFixture('discharge-summary.xml'));
        Storage::disk(PhrDocument::STORAGE_DISK)->delete((string) $missing->storage_path);
        $lost = $this->row($patient, '2.25.4062|admit-metformin', $missing->id);

        $this->artisan('phr:ccda:retract-hospital-medications', ['--patient' => $patient->id])
            ->expectsOutputToContain("Patient #{$patient->id}: 2 C-CDA medication row(s) have no stored source document, or one that cannot be read")
            ->expectsOutputToContain('2 could not be checked')
            ->assertSuccessful();

        $this->assertNull($unlinked->refresh()->retracted_at);
        $this->assertNull($lost->refresh()->retracted_at);
    }

    public function test_rows_the_narrative_table_reader_made_of_a_hospital_list_are_retracted(): void
    {
        $patient = $this->ccdaPatient();
        $document = $this->storedDocument($patient, 'narrative.xml', PhrCcdaNarrativeHospitalList::xml());
        $hospital = $this->narrativeRow($patient, PhrCcdaNarrativeHospitalList::HOSPITAL_ONLY, $document->id);
        $shared = $this->narrativeRow($patient, PhrCcdaNarrativeHospitalList::ALSO_HOME, $document->id);

        $this->artisan('phr:ccda:retract-hospital-medications')->assertSuccessful();

        $this->assertNotNull($hospital->refresh()->retracted_at);
        $this->assertNull($shared->refresh()->retracted_at, 'the same table row in the Medications section is a home medication');
    }

    /** @param list<string> $cells */
    private function narrativeRow(PhrPatient $patient, array $cells, int $documentId): PhrMedication
    {
        return PhrMedication::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'ccda',
            'external_id' => PhrCcdaNarrativeHospitalList::externalId($cells),
            'source_document_id' => $documentId,
            'review_status' => PhrReviewStatus::PENDING,
            'name' => $cells[0],
            'status' => 'active',
        ]);
    }

    private function row(PhrPatient $patient, string $rootAndExtension, ?int $documentId, string $review = PhrReviewStatus::PENDING): PhrMedication
    {
        return PhrMedication::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'ccda',
            'external_id' => 'ccda-med-'.sha1('id|'.$rootAndExtension),
            'source_document_id' => $documentId,
            'review_status' => $review,
            'name' => 'Synthetic medicine',
            'status' => 'active',
        ]);
    }

    private function storedDocument(PhrPatient $patient, string $filename, string $bytes): PhrDocument
    {
        $key = 'synthetic/'.$patient->id.'/'.$filename;
        Storage::disk(PhrDocument::STORAGE_DISK)->put($key, $bytes);

        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'uploaded_by_user_id' => $patient->owner_user_id,
            'title' => 'Synthetic stored document',
            'document_type' => 'other',
            'original_filename' => $filename,
            'storage_disk' => PhrDocument::STORAGE_DISK,
            'storage_path' => $key,
            'mime_type' => str_ends_with($filename, '.zip') ? 'application/zip' : 'application/xml',
            'byte_size' => strlen($bytes),
            'source' => 'agent_upload',
        ]);
    }

    private function homeList(string $root, string $extension): string
    {
        return $this->ccdaSectionDocument(<<<XML
            <templateId root="2.16.840.1.113883.10.20.22.2.1.1" extension="2014-06-09"/>
            <code code="10160-0" codeSystem="2.16.840.1.113883.6.1"/>
            <title>Medications</title>
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id root="{$root}" extension="{$extension}"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9994062" codeSystem="2.16.840.1.113883.6.88" displayName="metformin 500 mg oral tablet"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML);
    }

    /** @param array<string, string> $members */
    private function zip(array $members): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phr-ccda-zip-');
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
            foreach ($members as $name => $contents) {
                $zip->addFromString($name, $contents);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
