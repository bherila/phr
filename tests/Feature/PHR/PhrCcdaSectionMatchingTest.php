<?php

namespace Tests\Feature\PHR;

use App\Models\PhrAllergy;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrProcedure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ImportsCcdaFixtures;
use Tests\TestCase;

/**
 * A section is read by the reader its template, else its LOINC code, names; a
 * known section no reader reads is skipped; and only a section with neither a
 * known template nor a known code is matched by title, in the order the
 * narrative-table reader has always matched titles.
 */
final class PhrCcdaSectionMatchingTest extends TestCase
{
    use ImportsCcdaFixtures;

    public function test_a_section_template_wins_over_its_title(): void
    {
        $patient = $this->ccdaPatient();

        // "Medication" comes first among titles, but the template says allergies.
        $this->importCcda($this->document(<<<'XML'
            <templateId root="2.16.840.1.113883.10.20.22.2.6.1" extension="2015-08-01"/>
            <title>Medication Allergies</title>
            <text><paragraph>Synthetic allergy narrative</paragraph></text>
            <entry>
              <act classCode="ACT" moodCode="EVN">
                <templateId root="2.16.840.1.113883.10.20.22.4.30"/>
                <statusCode code="active"/>
                <entryRelationship typeCode="SUBJ">
                  <observation classCode="OBS" moodCode="EVN">
                    <templateId root="2.16.840.1.113883.10.20.22.4.7"/>
                    <id root="2.25.11001" extension="match-allergy"/>
                    <participant typeCode="CSM"><participantRole><playingEntity>
                      <code code="9991101" codeSystem="2.16.840.1.113883.6.88" displayName="Synthetic matched substance"/>
                    </playingEntity></participantRole></participant>
                  </observation>
                </entryRelationship>
              </act>
            </entry>
            XML), $patient);

        $this->assertSame(['Synthetic matched substance'], PhrAllergy::query()->where('patient_id', $patient->id)->pluck('substance')->all());
        $this->assertSame(0, PhrMedication::query()->where('patient_id', $patient->id)->count());
    }

    public function test_a_known_template_wins_over_a_results_like_title(): void
    {
        $patient = $this->ccdaPatient();

        // "Laboratory" in the title must not route a Procedures section to Results.
        $this->importCcda($this->document(<<<'XML'
            <templateId root="2.16.840.1.113883.10.20.22.2.7.1" extension="2014-06-09"/>
            <title>Laboratory Procedures</title>
            <text><table><tbody><tr><td>Synthetic lab procedure</td><td>01/02/2026</td><td/></tr></tbody></table></text>
            <entry>
              <procedure classCode="PROC" moodCode="EVN">
                <templateId root="2.16.840.1.113883.10.20.22.4.14"/>
                <id root="2.25.11003" extension="match-procedure"/>
                <code code="9991103" codeSystem="2.16.840.1.113883.6.96" displayName="Synthetic lab procedure"/>
                <statusCode code="completed"/>
                <effectiveTime value="20260102"/>
              </procedure>
            </entry>
            XML), $patient);

        $this->assertSame(['Synthetic lab procedure'], PhrProcedure::query()->where('patient_id', $patient->id)->pluck('name')->all());
        $this->assertSame(0, PhrLabResult::query()->where('patient_id', $patient->id)->count());
    }

    public function test_a_section_without_a_known_template_or_code_is_read_by_its_title(): void
    {
        $patient = $this->ccdaPatient();

        $this->importCcda($this->document(<<<'XML'
            <templateId root="2.25.11999.1"/>
            <title>Current Medications</title>
            <text><paragraph>Synthetic medication narrative</paragraph></text>
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id root="2.25.11002" extension="match-medication"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9991102" codeSystem="2.16.840.1.113883.6.88" displayName="Synthetic titled medicine"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML), $patient);

        $this->assertSame(['Synthetic titled medicine'], PhrMedication::query()->where('patient_id', $patient->id)->pluck('name')->all());
    }

    public function test_a_section_code_routes_a_section_without_a_template_whatever_its_title(): void
    {
        $patient = $this->ccdaPatient();

        // "Medication" comes first among titles, but the code says allergies.
        $this->importCcda($this->document(<<<'XML'
            <code code="48765-2" codeSystem="2.16.840.1.113883.6.1"/>
            <title>Medication Allergies</title>
            <text><table><tbody><tr><td>Synthetic coded substance</td><td/><td/></tr></tbody></table></text>
            XML), $patient);

        $this->assertSame(['Synthetic coded substance'], PhrAllergy::query()->where('patient_id', $patient->id)->pluck('substance')->all());
        $this->assertSame(0, PhrMedication::query()->where('patient_id', $patient->id)->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadSections(): array
    {
        return [
            'admission medications code, titled as the home list' => ['<code code="42346-7" codeSystem="2.16.840.1.113883.6.1"/><title>Medications</title>'],
            'admission medications title, uncoded' => ['<title>Admission Medications</title>'],
            'administered medications code' => ['<code code="29549-3" codeSystem="2.16.840.1.113883.6.1"/><title>Medication Administration</title>'],
            'administered medications title, uncoded' => ['<title>Medications Administered</title>'],
            'reason for visit title, uncoded' => ['<title>Reason for Visit</title>'],
            'plan of treatment code' => ['<code code="18776-5" codeSystem="2.16.840.1.113883.6.1"/><title>Planned Encounters and Procedures</title>'],
        ];
    }

    /** A known section no reader reads is skipped, never read as the kind its title names. */
    #[DataProvider('unreadSections')]
    public function test_a_known_section_no_reader_reads_is_skipped(string $header): void
    {
        $patient = $this->ccdaPatient();

        $result = $this->importCcda($this->document($header.<<<'XML'
            <text><table><tbody><tr><td>01/02/2026</td><td>Synthetic unread row</td><td/><td/><td/></tr></tbody></table></text>
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id root="2.25.11004" extension="unread-medication"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9991104" codeSystem="2.16.840.1.113883.6.88" displayName="Synthetic unread medicine"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML), $patient);

        $this->assertSame([0, 1], [$result->created, $result->skipped]);
        foreach ([PhrMedication::class, PhrProcedure::class, PhrOfficeVisit::class] as $model) {
            $this->assertSame(0, $model::query()->where('patient_id', $patient->id)->count(), $model);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hospitalMedicationLists(): array
    {
        return [
            'discharge medications template' => ['<templateId root="2.16.840.1.113883.10.20.22.2.11.1" extension="2015-08-01"/><title>Discharge Medications</title>', 'discharge'],
            'discharge medications title, uncoded' => ['<title>Hospital Discharge Medications</title>', 'discharge'],
        ];
    }

    /**
     * A discharge medication list is read by its code (or exact title) as the
     * discharge list (#413), never by a title word as the home list.
     */
    #[DataProvider('hospitalMedicationLists')]
    public function test_a_hospital_medication_list_is_read_with_its_setting(string $header, string $category): void
    {
        $patient = $this->ccdaPatient();

        $result = $this->importCcda($this->document($header.<<<'XML'
            <text><table><tbody><tr><td>Synthetic listed medicine</td><td/><td/></tr></tbody></table></text>
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id root="2.25.11004" extension="listed-medication"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9991104" codeSystem="2.16.840.1.113883.6.88" displayName="Synthetic listed medicine"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML), $patient);

        $this->assertSame([1, 0], [$result->created, $result->skipped]);
        $medication = PhrMedication::query()->where('patient_id', $patient->id)->sole();
        $this->assertSame($category, $medication->category);
        $this->assertSame($category.':ccda-med-'.sha1('id|2.25.11004|listed-medication'), $medication->external_id);
    }

    public function test_titles_keep_the_narrative_reader_order(): void
    {
        $patient = $this->ccdaPatient();

        // No template and no entries: the title decides, and "medication" is
        // matched before "allerg", as it always was.
        $this->importCcda($this->document(<<<'XML'
            <title>Medication Allergies</title>
            <text><table><tbody><tr><td>Synthetic titled row</td><td/><td/></tr></tbody></table></text>
            XML), $patient);

        $this->assertSame(['Synthetic titled row'], PhrMedication::query()->where('patient_id', $patient->id)->pluck('name')->all());
        $this->assertSame(0, PhrAllergy::query()->where('patient_id', $patient->id)->count());
    }

    private function document(string $section): string
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
}
