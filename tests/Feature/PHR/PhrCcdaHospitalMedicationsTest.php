<?php

namespace Tests\Feature\PHR;

use App\Models\PhrDocument;
use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrProcedure;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrImportResult;
use App\Services\PHR\Import\PhrMyChartZipImporter;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportsCcdaFixtures;
use Tests\Support\PhrCcdaNarrativeHospitalList;
use Tests\Support\UsesObjectStoreDisks;
use Tests\TestCase;
use ZipArchive;

/**
 * A discharge summary's hospital medication lists (admission, administered,
 * discharge) and its procedure and visit sections are routed by their codes,
 * never by titles that share a word with a section PHR reads (#406), and the
 * medication rows earlier imports made of those lists are retracted when the
 * document is imported again. Since #413 the discharge list is read as its own
 * setting, under ids scoped by it, beside the home list; the admission list
 * (what the patient took before admission) and the administered list stay
 * skipped.
 */
final class PhrCcdaHospitalMedicationsTest extends TestCase
{
    use ImportsCcdaFixtures;
    use UsesObjectStoreDisks;

    /** The Medications section's names: the only home medications the fixture holds. */
    private const array HOME = ['lisinopril 10 mg oral tablet', 'metformin 500 mg oral tablet', 'acetaminophen 500 mg oral tablet'];

    /**
     * The hospital-list activities' ids, as `root|extension`, that the import
     * before #406 made medication rows of. The discharge list's lisinopril
     * reuses the home list's id and so is not among them.
     */
    private const array HOSPITAL = [
        'admit-lisinopril' => '2.25.4062|admit-lisinopril',
        'admit-metformin' => '2.25.4062|admit-metformin',
        'admit-acetaminophen' => '2.25.4062|admit-acetaminophen',
        'given-ceftriaxone' => '2.25.4063|given-ceftriaxone',
        'given-potassium' => '2.25.4063|given-potassium',
        'given-insulin' => '2.25.4063|given-insulin',
        'given-saline' => '2.25.4063|given-saline',
        'discharge-metformin' => '2.25.4064|discharge-metformin',
        'discharge-cefpodoxime' => '2.25.4064|discharge-cefpodoxime',
    ];

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

    public function test_only_the_medications_section_becomes_home_medications(): void
    {
        $patient = $this->ccdaPatient();

        $result = $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        $this->assertSame(self::HOME, $this->names($patient, 'community'));
        // The discharge list is the discharge list (#413); the admission list is skipped.
        $this->assertSame([], $this->names($patient, 'inpatient'));
        $this->assertSame(['lisinopril 10 mg oral tablet', 'metformin 500 mg oral tablet', 'cefpodoxime 200 mg oral tablet'], $this->names($patient, 'discharge'));
        // Three home medications, three discharge ones, and the stay; every
        // admission and administered entry and every row of the procedure,
        // visit-reason and diagnosis sections is counted as skipped.
        $this->assertSame(['created' => 7, 'skipped' => 12, 'warnings' => []], ['created' => $result->created, 'skipped' => $result->skipped, 'warnings' => $result->warnings]);
    }

    public function test_the_same_activity_in_the_home_and_the_discharge_list_is_two_rows(): void
    {
        $patient = $this->ccdaPatient();

        $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);
        $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        // The discharge list's lisinopril reuses the home list's activity id.
        $home = $this->externalId('2.25.4061|home-lisinopril');
        $this->assertSame(['community'], PhrMedication::query()->where('patient_id', $patient->id)->where('external_id', $home)->pluck('category')->all());
        $this->assertSame(['discharge'], PhrMedication::query()->where('patient_id', $patient->id)->where('external_id', 'discharge:'.$home)->pluck('category')->all());
    }

    public function test_reimporting_the_discharge_summary_changes_nothing(): void
    {
        $patient = $this->ccdaPatient();
        $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        $again = $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 7, 'warnings' => []], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged, 'warnings' => $again->warnings]);
        $this->assertSame(self::HOME, $this->names($patient, 'community'));
        $this->assertSame(6, PhrMedication::query()->where('patient_id', $patient->id)->count());
    }

    public function test_coded_procedure_visit_and_diagnosis_sections_create_no_procedures_or_visits(): void
    {
        $patient = $this->ccdaPatient();

        $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        $this->assertSame(0, PhrProcedure::query()->where('patient_id', $patient->id)->count(), 'Procedure Findings, Postprocedure Diagnosis and Planned Procedure are not procedures');
        // Only the Encounters section's stay: Reason for Visit and Encounter Diagnosis are not visits.
        $this->assertSame(['inpatient encounter'], PhrOfficeVisit::query()->where('patient_id', $patient->id)->pluck('visit_type')->all());
    }

    public function test_an_activity_inside_a_discharge_medication_act_is_never_a_home_medication(): void
    {
        $patient = $this->ccdaPatient();

        // Even in a section the Medications reader reads as the home list, a
        // hospital list's wrapped activity is that list's.
        $result = $this->importCcda($this->ccdaSectionDocument(<<<'XML'
            <templateId root="2.16.840.1.113883.10.20.22.2.1.1" extension="2014-06-09"/>
            <code code="10160-0" codeSystem="2.16.840.1.113883.6.1"/>
            <title>Medications</title>
            <entry>
              <act classCode="ACT" moodCode="EVN">
                <templateId root="2.16.840.1.113883.10.20.22.4.35" extension="2016-03-01"/>
                <entryRelationship typeCode="SUBJ">
                  <substanceAdministration classCode="SBADM" moodCode="INT">
                    <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                    <id root="2.25.4067" extension="wrapped"/>
                    <consumable><manufacturedProduct><manufacturedMaterial>
                      <code code="9994069" codeSystem="2.16.840.1.113883.6.88" displayName="cefpodoxime 200 mg oral tablet"/>
                    </manufacturedMaterial></manufacturedProduct></consumable>
                  </substanceAdministration>
                </entryRelationship>
              </act>
            </entry>
            XML), $patient);

        $medication = PhrMedication::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame(['discharge', 'discharge:'.$this->externalId('2.25.4067|wrapped')], [$medication->category, $medication->external_id]);
        $this->assertSame([1, 0], [$result->created, $result->skipped]);
    }

    public function test_an_activity_inside_an_admission_medication_act_is_skipped(): void
    {
        $patient = $this->ccdaPatient();

        // What the patient took before admission is the home list again under
        // other ids, wherever its act sits: skipped and counted.
        $result = $this->importCcda($this->ccdaSectionDocument(<<<'XML'
            <templateId root="2.16.840.1.113883.10.20.22.2.1.1" extension="2014-06-09"/>
            <code code="10160-0" codeSystem="2.16.840.1.113883.6.1"/>
            <title>Medications</title>
            <entry>
              <act classCode="ACT" moodCode="EVN">
                <templateId root="2.16.840.1.113883.10.20.22.4.36" extension="2014-06-09"/>
                <entryRelationship typeCode="SUBJ">
                  <substanceAdministration classCode="SBADM" moodCode="EVN">
                    <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                    <id root="2.25.4068" extension="admitted"/>
                    <consumable><manufacturedProduct><manufacturedMaterial>
                      <code code="9994061" codeSystem="2.16.840.1.113883.6.88" displayName="lisinopril 10 mg oral tablet"/>
                    </manufacturedMaterial></manufacturedProduct></consumable>
                  </substanceAdministration>
                </entryRelationship>
              </act>
            </entry>
            XML), $patient);

        $this->assertSame(0, PhrMedication::query()->where('patient_id', $patient->id)->count());
        $this->assertSame([0, 1], [$result->created, $result->skipped]);
    }

    public function test_reimporting_a_document_retracts_the_rows_an_earlier_import_made_of_its_hospital_lists(): void
    {
        $patient = $this->ccdaPatient();
        $document = $this->document($patient);
        $other = $this->document($patient);
        $this->import($patient, $document);
        $home = PhrMedication::query()->where('patient_id', $patient->id)->orderBy('id')->pluck('id')->all();
        // What the import before #406 wrote: one row per hospital-list activity.
        $rows = [];
        foreach (self::HOSPITAL as $key => $id) {
            $rows[$key] = $this->misroutedRow($patient, $id, $document->id);
        }
        // A row an unlinked import of the same file made is this document's too.
        $rows['admit-acetaminophen']->forceFill(['source_document_id' => null])->save();
        // A row linked to another document is left to the command, which reads both.
        $rows['admit-metformin']->forceFill(['source_document_id' => $other->id])->save();
        // What a person decided stays theirs.
        $rows['discharge-metformin']->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $this->fillFor($patient, $rows['given-ceftriaxone']);
        $rows['given-potassium']->forceFill(['review_status' => PhrReviewStatus::REJECTED])->save();

        $result = $this->import($patient, $document);

        $retracted = ['admit-lisinopril', 'admit-acetaminophen', 'given-potassium', 'given-insulin', 'given-saline', 'discharge-cefpodoxime'];
        $this->assertSame($retracted, $this->retractedKeys($rows));
        $this->assertSame([0, 0], [$result->created, $result->updated]);
        $this->assertSame([
            '6 medication(s) an earlier import read from a hospital medication list (admission, administered or discharge) were retracted: they are not home medications.',
            '1 medication(s) a person confirmed came from a hospital medication list and were left as they are; check whether they are home medications.',
            '1 medication(s) from a hospital medication list have prescription fills linked to them and were left as they are; check whether they are home medications.',
        ], $result->warnings);
        // Retracted, not deleted, and the review a row had is kept.
        $this->assertSame(PhrReviewStatus::REJECTED, $rows['given-potassium']->refresh()->review_status);
        $this->assertSame(PhrReviewStatus::PENDING, $rows['given-saline']->refresh()->review_status);
        // The home list, the discharge list's lisinopril among it, stands.
        $this->assertSame($home, PhrMedication::query()->where('patient_id', $patient->id)->whereNull('retracted_at')->whereIn('id', $home)->orderBy('id')->pluck('id')->all());

        $retractedAt = PhrMedication::query()->where('patient_id', $patient->id)->whereNotNull('retracted_at')->orderBy('id')->pluck('retracted_at', 'id')->map(fn ($at): string => (string) $at)->all();
        Carbon::setTestNow(now()->addHour());
        try {
            $again = $this->import($patient, $document);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($retracted, $this->retractedKeys($rows), 'nothing more is retracted');
        $this->assertSame($retractedAt, PhrMedication::query()->where('patient_id', $patient->id)->whereNotNull('retracted_at')->orderBy('id')->pluck('retracted_at', 'id')->map(fn ($at): string => (string) $at)->all(), 'nor retracted again');
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 7], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged]);
        $this->assertCount(2, $again->warnings, 'only the rows left for a person are reported again');
        // The discharge list the misrouted rows came from stands beside them, as its
        // own setting; the admission list writes nothing.
        $this->assertSame([], $this->names($patient, 'inpatient'));
        $this->assertSame(['lisinopril 10 mg oral tablet', 'metformin 500 mg oral tablet', 'cefpodoxime 200 mg oral tablet'], $this->names($patient, 'discharge'));
    }

    public function test_a_document_whose_medication_list_names_a_retracted_row_restores_it(): void
    {
        $patient = $this->ccdaPatient();
        $document = $this->document($patient);
        $this->import($patient, $document);
        $lisinopril = PhrMedication::query()->where('patient_id', $patient->id)->where('external_id', $this->externalId('2.25.4061|home-lisinopril'))->sole();
        $lisinopril->forceFill(['retracted_at' => now()])->save();

        $result = $this->import($patient, $document);

        $this->assertNull($lisinopril->refresh()->retracted_at);
        $this->assertSame(['1 medication(s) this document lists as current medications were restored after an earlier import retracted them.'], $result->warnings);
    }

    public function test_a_mychart_zip_retracts_only_what_no_member_lists_as_a_home_medication(): void
    {
        $patient = $this->ccdaPatient();
        $document = $this->document($patient, 'mychart.zip');
        // Another member's own medication list names the discharge list's metformin.
        $summary = $this->ccdaSectionDocument(<<<'XML'
            <templateId root="2.16.840.1.113883.10.20.22.2.1.1" extension="2014-06-09"/>
            <code code="10160-0" codeSystem="2.16.840.1.113883.6.1"/>
            <title>Medications</title>
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id root="2.25.4064" extension="discharge-metformin"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9994062" codeSystem="2.16.840.1.113883.6.88" displayName="metformin 500 mg oral tablet"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML);
        $zip = $this->zip(['1-discharge.xml' => $this->ccdaFixture('discharge-summary.xml'), '2-summary.xml' => $summary]);
        $rows = [];
        foreach (self::HOSPITAL as $key => $id) {
            $rows[$key] = $this->misroutedRow($patient, $id, $document->id);
        }

        try {
            app(PhrMyChartZipImporter::class)->importFile($zip, $patient, $patient->owner_user_id, $document);
        } finally {
            @unlink($zip);
        }

        $this->assertSame(array_values(array_diff(array_keys(self::HOSPITAL), ['discharge-metformin'])), $this->retractedKeys($rows));
    }

    public function test_a_stored_summary_listing_the_id_as_a_home_medication_keeps_the_row(): void
    {
        $patient = $this->ccdaPatient();
        $document = $this->document($patient);
        // A later summary lists the discharge metformin as a home medication. The
        // discharge summary wrote the row first, so the row links to it, not to the summary.
        $summary = $this->document($patient, 'summary.xml');
        Storage::disk(PhrDocument::STORAGE_DISK)->put('synthetic/summary.xml', $this->homeList('2.25.4064', 'discharge-metformin'));
        $summary->forceFill(['storage_disk' => PhrDocument::STORAGE_DISK, 'storage_path' => 'synthetic/summary.xml'])->save();
        $metformin = $this->misroutedRow($patient, self::HOSPITAL['discharge-metformin'], $document->id);
        $cefpodoxime = $this->misroutedRow($patient, self::HOSPITAL['discharge-cefpodoxime'], $document->id);

        $this->import($patient, $document);

        $this->assertNull($metformin->refresh()->retracted_at);
        $this->assertNotNull($cefpodoxime->refresh()->retracted_at);
    }

    public function test_rows_the_narrative_table_reader_made_of_a_hospital_list_are_retracted_on_reimport(): void
    {
        $patient = $this->ccdaPatient();
        $document = $this->document($patient);
        $row = fn (array $cells): PhrMedication => PhrMedication::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'ccda',
            'external_id' => PhrCcdaNarrativeHospitalList::externalId($cells),
            'source_document_id' => $document->id,
            'review_status' => PhrReviewStatus::PENDING,
            'name' => $cells[0],
            'status' => 'active',
        ]);
        // What the narrative-table reader stored, before entries were read.
        $hospital = $row(PhrCcdaNarrativeHospitalList::HOSPITAL_ONLY);
        $shared = $row(PhrCcdaNarrativeHospitalList::ALSO_HOME);

        app(PhrCcdaImporter::class)->importXml(PhrCcdaNarrativeHospitalList::xml(), $patient, $patient->owner_user_id, $document);

        $this->assertNotNull($hospital->refresh()->retracted_at);
        // The Medications section's entry references the same row: it is that
        // entry's row, adopted onto the entry's id, never retracted.
        $this->assertNull($shared->refresh()->retracted_at);
        $this->assertSame(['metformin 500 mg oral tablet'], $this->names($patient, 'community'));
        // The discharge list is written as its own rows, under scoped table-row ids.
        $this->assertSame(['cefpodoxime 200 mg oral tablet', 'metformin 500 mg oral tablet'], $this->names($patient, 'discharge'));
    }

    /** @return list<string> the patient's live medications of a setting, in the order they were written */
    private function names(PhrPatient $patient, string $category): array
    {
        return PhrMedication::query()->where('patient_id', $patient->id)->where('category', $category)->whereNull('retracted_at')->orderBy('id')->pluck('name')->all();
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

    private function import(PhrPatient $patient, PhrDocument $document): PhrImportResult
    {
        return app(PhrCcdaImporter::class)->importXml($this->ccdaFixture('discharge-summary.xml'), $patient, $patient->owner_user_id, $document);
    }

    /**
     * @param  array<string, PhrMedication>  $rows
     * @return list<string>
     */
    private function retractedKeys(array $rows): array
    {
        return array_keys(array_filter($rows, static fn (PhrMedication $row): bool => $row->refresh()->retracted_at !== null));
    }

    private function misroutedRow(PhrPatient $patient, string $id, int $documentId): PhrMedication
    {
        return PhrMedication::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'import_source' => 'ccda',
            'external_id' => $this->externalId($id),
            'source_document_id' => $documentId,
            'review_status' => PhrReviewStatus::PENDING,
            'name' => 'Synthetic hospital-list medicine',
            'status' => 'active',
        ]);
    }

    /** The id the Medications reader gives an activity that has an id. */
    private function externalId(string $rootAndExtension): string
    {
        return 'ccda-med-'.sha1('id|'.$rootAndExtension);
    }

    private function fillFor(PhrPatient $patient, PhrMedication $medication): void
    {
        PhrMedicationFill::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'medication_id' => $medication->id,
            'filled_on' => '2026-02-12',
            'quantity' => '1',
            'ndc_status' => PhrMedicationFill::NDC_MANUAL,
            'drug_as_printed' => 'Synthetic drug',
            'review_status' => PhrReviewStatus::PENDING,
            'source_kind' => 'manual',
        ]);
    }

    private function document(PhrPatient $patient, string $filename = 'discharge-summary.xml'): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'uploaded_by_user_id' => $patient->owner_user_id,
            'title' => 'Synthetic discharge summary',
            'document_type' => 'other',
            'original_filename' => $filename,
            'mime_type' => 'application/xml',
            'byte_size' => 1,
            'source' => 'agent_upload',
        ]);
    }

    /** @param array<string, string> $members */
    private function zip(array $members): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phr-ccda-zip-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        foreach ($members as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
