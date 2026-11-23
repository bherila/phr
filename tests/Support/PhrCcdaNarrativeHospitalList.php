<?php

namespace Tests\Support;

/**
 * A synthetic C-CDA (issue #405's fixture) whose hospital discharge list has
 * structured entries that reference their narrative table rows, for the rows
 * the narrative-table reader stored under a hash of those cells before the
 * structured reader existed. One discharge row is the same as a row of the
 * Medications section, which keeps that hash a home medication's.
 */
final class PhrCcdaNarrativeHospitalList
{
    /** A row only the discharge list has. */
    public const array HOSPITAL_ONLY = ['cefpodoxime 200 mg oral tablet', 'Take 200 mg by mouth twice daily for 5 days'];

    /** A row both the Medications section and the discharge list have. */
    public const array ALSO_HOME = ['metformin 500 mg oral tablet', 'Take 500 mg by mouth twice daily'];

    /**
     * The id the narrative-table reader gave a medication row.
     *
     * @param  list<string>  $cells
     */
    public static function externalId(array $cells): string
    {
        return 'ccda-med-'.sha1(implode('|', $cells));
    }

    public static function xml(): string
    {
        [$hospitalName, $hospitalSig] = self::HOSPITAL_ONLY;
        [$homeName, $homeSig] = self::ALSO_HOME;
        $home = '<entry>'.self::activity('2.25.4070', 'home-metformin', 'home-row', $homeName).'</entry>';
        $dischargeOnly = self::wrapped(self::activity('2.25.4071', 'discharge-cefpodoxime', 'dc-row-1', $hospitalName));
        $dischargeHome = self::wrapped(self::activity('2.25.4071', 'discharge-metformin', 'dc-row-2', $homeName));

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
              <recordTarget><patientRole><patient><name><given>Pat</given><family>Example</family></name><birthTime value="19500615"/></patient></patientRole></recordTarget>
              <component><structuredBody>
                <component><section>
                  <templateId root="2.16.840.1.113883.10.20.22.2.1.1" extension="2014-06-09"/>
                  <code code="10160-0" codeSystem="2.16.840.1.113883.6.1"/>
                  <title>Medications</title>
                  <text><table><tbody><tr ID="home-row"><td>{$homeName}</td><td>{$homeSig}</td></tr></tbody></table></text>
                  {$home}
                </section></component>
                <component><section>
                  <templateId root="2.16.840.1.113883.10.20.22.2.11.1" extension="2015-08-01"/>
                  <code code="10183-2" codeSystem="2.16.840.1.113883.6.1"/>
                  <title>Hospital Discharge Medications</title>
                  <text><table><tbody>
                    <tr ID="dc-row-1"><td>{$hospitalName}</td><td>{$hospitalSig}</td></tr>
                    <tr ID="dc-row-2"><td>{$homeName}</td><td>{$homeSig}</td></tr>
                  </tbody></table></text>
                  {$dischargeOnly}
                  {$dischargeHome}
                </section></component>
              </structuredBody></component>
            </ClinicalDocument>
            XML;
    }

    private static function activity(string $root, string $extension, string $row, string $name): string
    {
        return <<<XML
            <substanceAdministration classCode="SBADM" moodCode="INT">
              <templateId root="2.16.840.1.113883.10.20.22.4.16"/>
              <id root="{$root}" extension="{$extension}"/>
              <text><reference value="#{$row}"/></text>
              <consumable><manufacturedProduct><manufacturedMaterial>
                <code code="9994070" codeSystem="2.16.840.1.113883.6.88" displayName="{$name}"/>
              </manufacturedMaterial></manufacturedProduct></consumable>
            </substanceAdministration>
            XML;
    }

    private static function wrapped(string $activity): string
    {
        return <<<XML
            <entry><act classCode="ACT" moodCode="EVN">
              <templateId root="2.16.840.1.113883.10.20.22.4.35" extension="2016-03-01"/>
              <entryRelationship typeCode="SUBJ">{$activity}</entryRelationship>
            </act></entry>
            XML;
    }
}
