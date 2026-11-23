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
use Tests\Support\ImportsCcdaFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * A discharge summary's hospital medication lists (admission, administered,
 * discharge) and its procedure and visit sections are routed by their codes,
 * never by titles that share a word with a section PHR reads (#406), and the
 * medication rows earlier imports made of those lists are retracted when the
 * document is imported again.
 */
final class PhrCcdaHospitalMedicationsTest extends TestCase
{
    use ImportsCcdaFixtures;

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

    public function test_only_the_medications_section_becomes_medications(): void
    {
        $patient = $this->ccdaPatient();

        $result = $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        $this->assertSame(self::HOME, PhrMedication::query()->where('patient_id', $patient->id)->orderBy('id')->pluck('name')->all());
        // Three home medications and the stay; every hospital-list entry and
        // every row of the procedure, visit-reason and diagnosis sections is
        // counted as skipped.
        $this->assertSame(['created' => 4, 'skipped' => 15, 'warnings' => []], ['created' => $result->created, 'skipped' => $result->skipped, 'warnings' => $result->warnings]);
    }

    public function test_reimporting_the_discharge_summary_changes_nothing(): void
    {
        $patient = $this->ccdaPatient();
        $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        $again = $this->importCcda($this->ccdaFixture('discharge-summary.xml'), $patient);

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 4, 'warnings' => []], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged, 'warnings' => $again->warnings]);
        $this->assertSame(self::HOME, PhrMedication::query()->where('patient_id', $patient->id)->orderBy('id')->pluck('name')->all());
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

        // Even in a section the Medications reader reads, a hospital list's
        // wrapped activity is skipped.
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
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 4], ['created' => $again->created, 'updated' => $again->updated, 'unchanged' => $again->unchanged]);
        $this->assertCount(2, $again->warnings, 'only the rows left for a person are reported again');
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
