<?php

namespace Tests\Feature\PHR;

use App\Models\PhrAllergy;
use App\Models\PhrLabResult;
use App\Models\PhrMedication;
use App\Models\PhrProcedure;
use Tests\Support\ImportsCcdaFixtures;
use Tests\TestCase;

/**
 * A section is read by the reader its template names, and only a section
 * without a known template by its title, in the order the narrative-table
 * reader has always matched titles.
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

    public function test_a_section_without_a_known_template_is_read_by_its_title(): void
    {
        $patient = $this->ccdaPatient();

        $this->importCcda($this->document(<<<'XML'
            <templateId root="2.16.840.1.113883.10.20.22.2.11.1" extension="2015-08-01"/>
            <title>Discharge Medications</title>
            <text><paragraph>Synthetic discharge narrative</paragraph></text>
            <entry>
              <substanceAdministration classCode="SBADM" moodCode="INT">
                <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
                <id root="2.25.11002" extension="match-medication"/>
                <consumable><manufacturedProduct><manufacturedMaterial>
                  <code code="9991102" codeSystem="2.16.840.1.113883.6.88" displayName="Synthetic discharge medicine"/>
                </manufacturedMaterial></manufacturedProduct></consumable>
              </substanceAdministration>
            </entry>
            XML), $patient);

        $this->assertSame(['Synthetic discharge medicine'], PhrMedication::query()->where('patient_id', $patient->id)->pluck('name')->all());
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
