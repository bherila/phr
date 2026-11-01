<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Export\PhrCcdaExporter;
use App\Services\PHR\Import\PhrCcdaImporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * Whether a lab reference bound includes its own value (phr#259). Each bound
 * stores it as true, false, or null when the source did not say; null keeps
 * every view and export exactly as before the attribute existed. Synthetic data
 * only.
 */
final class PhrLabReferenceBoundInclusiveTest extends TestCase
{
    use ConfiguresPassportKeys;

    /**
     * analyte => [<low> element or null, <high> element or null, expected [range_min, range_max, range_min_inclusive, range_max_inclusive]]
     *
     * @return array<string, array{0: string|null, 1: string|null, 2: array{0: string|null, 1: string|null, 2: bool|null, 3: bool|null}}>
     */
    private static function ccdaShapes(): array
    {
        return [
            'Synthetic high inclusive' => [null, '<high value="5" inclusive="true" unit="mg/dL"/>', [null, '5', null, true]],
            'Synthetic high exclusive' => [null, '<high value="5" inclusive="false" unit="mg/dL"/>', [null, '5', null, false]],
            'Synthetic high unstated' => [null, '<high value="5" unit="mg/dL"/>', [null, '5', null, null]],
            'Synthetic low inclusive' => ['<low value="60" inclusive="true" unit="mL/min"/>', null, ['60', null, true, null]],
            'Synthetic low exclusive' => ['<low value="60" inclusive="false" unit="mL/min"/>', null, ['60', null, false, null]],
            'Synthetic low unstated' => ['<low value="60" unit="mL/min"/>', null, ['60', null, null, null]],
            // xs:boolean also spells them 1 and 0.
            'Synthetic both numeric flags' => ['<low value="1" inclusive="1" unit="g/dL"/>', '<high value="9" inclusive="0" unit="g/dL"/>', ['1', '9', true, false]],
            // A bound with no number has nothing for its flag to describe.
            'Synthetic open low' => ['<low nullFlavor="NINF" inclusive="false" unit="mg/dL"/>', '<high value="200" inclusive="true" unit="mg/dL"/>', [null, '200', null, true]],
        ];
    }

    public function test_a_ccda_bound_keeps_the_inclusiveness_it_states_and_only_that(): void
    {
        $patient = $this->patient();

        app(PhrCcdaImporter::class)->importXml($this->ccda(self::ccdaShapes()), $patient, $patient->owner_user_id);

        foreach (self::ccdaShapes() as $analyte => [, , $expected]) {
            $row = PhrLabResult::query()->where('patient_id', $patient->id)->where('analyte', $analyte)->sole();
            $this->assertSame($expected, [
                $this->decimal($row->range_min),
                $this->decimal($row->range_max),
                $row->range_min_inclusive,
                $row->range_max_inclusive,
            ], $analyte);
            $this->assertNull($row->reference_range_text, "{$analyte}: no text is made up from the bounds");
        }
    }

    public function test_reimporting_a_ccda_with_stated_bounds_changes_nothing(): void
    {
        $patient = $this->patient();
        $xml = $this->ccda(self::ccdaShapes());

        app(PhrCcdaImporter::class)->importXml($xml, $patient, $patient->owner_user_id);
        $again = app(PhrCcdaImporter::class)->importXml($xml, $patient, $patient->owner_user_id);

        $this->assertSame(['created' => 0, 'updated' => 0], ['created' => $again->created, 'updated' => $again->updated]);
    }

    public function test_a_fhir_reference_bound_keeps_the_comparator_it_states(): void
    {
        $patient = $this->patient();
        $observation = static fn (string $id, array $range): array => ['resource' => [
            'resourceType' => 'Observation',
            'id' => $id,
            'category' => [['coding' => [['code' => 'laboratory']]]],
            'code' => ['text' => "Synthetic {$id}"],
            'effectiveDateTime' => '2026-01-15T10:00:00-08:00',
            'valueQuantity' => ['value' => 3, 'unit' => 'mg/dL'],
            'referenceRange' => [$range],
        ]];

        app(PhrFhirImporter::class)->importBundle(['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            $observation('at-most', ['high' => ['value' => 5, 'unit' => 'mg/dL', 'comparator' => '<=']]),
            $observation('below', ['high' => ['value' => 5, 'unit' => 'mg/dL', 'comparator' => '<']]),
            $observation('at-least', ['low' => ['value' => 60, 'unit' => 'mL/min', 'comparator' => '>=']]),
            $observation('above', ['low' => ['value' => 60, 'unit' => 'mL/min', 'comparator' => '>']]),
            $observation('bare', ['low' => ['value' => 1, 'unit' => 'g/dL'], 'high' => ['value' => 9, 'unit' => 'g/dL']]),
            // A comparator on a bound with no value describes nothing.
            $observation('valueless', ['low' => ['comparator' => '>='], 'high' => ['value' => 9, 'comparator' => 'ad']]),
        ]], $patient, $patient->owner_user_id);

        $flags = PhrLabResult::query()->where('patient_id', $patient->id)->orderBy('analyte')->get()
            ->mapWithKeys(fn (PhrLabResult $row): array => [(string) $row->analyte => [$row->range_min_inclusive, $row->range_max_inclusive]])
            ->all();
        $this->assertSame([
            'Synthetic above' => [false, null],
            'Synthetic at-least' => [true, null],
            'Synthetic at-most' => [null, true],
            'Synthetic bare' => [null, null],
            'Synthetic below' => [null, false],
            'Synthetic valueless' => [null, null],
        ], $flags);
    }

    public function test_an_exported_inclusive_bound_reads_back_with_its_comparator(): void
    {
        $patient = $this->patient();
        $xml = (new PhrCcdaExporter)->documentXml([
            'patient' => $patient,
            'lab_results' => collect([
                $this->unsavedLab(1, 'Synthetic at most', ['range_max' => '5', 'range_max_inclusive' => true, 'range_unit' => 'mg/dL']),
                $this->unsavedLab(2, 'Synthetic at least', ['range_min' => '60', 'range_min_inclusive' => true, 'range_unit' => 'mL/min']),
                $this->unsavedLab(3, 'Synthetic below', ['range_max' => '5', 'range_max_inclusive' => false, 'range_unit' => 'mg/dL']),
                $this->unsavedLab(4, 'Synthetic unstated', ['range_max' => '5', 'range_unit' => 'mg/dL']),
            ]),
            ...array_fill_keys(['vitals', 'conditions', 'medications', 'procedures', 'immunizations', 'allergies', 'office_visits', 'portal_messages', 'negative_assertions', 'dicom_studies', 'documents'], collect()),
        ]);

        // PHR's export is a narrative table, so the comparator travels as the
        // range text the reimport keeps and the lab views show as written.
        app(PhrCcdaImporter::class)->importXml($xml, $patient, $patient->owner_user_id);

        $this->assertSame([
            'Synthetic at least' => '>=60 mL/min',
            'Synthetic at most' => '<=5 mg/dL',
            'Synthetic below' => '<5 mg/dL',
            'Synthetic unstated' => '<5 mg/dL',
        ], PhrLabResult::query()->where('patient_id', $patient->id)->orderBy('analyte')->pluck('reference_range_text', 'analyte')->all());
    }

    public function test_a_bound_written_without_its_inclusiveness_leaves_it_unknown(): void
    {
        $patient = $this->patient();
        $lab = $this->savedLab($patient, ['range_min' => '1', 'range_min_inclusive' => true, 'range_max' => '9', 'range_max_inclusive' => false]);

        // An unchanged bound keeps what the source said of it.
        $lab->update(['range_min' => '1.0', 'range_max' => '9', 'notes' => 'Synthetic note']);
        $this->assertSame([true, false], [$lab->refresh()->range_min_inclusive, $lab->range_max_inclusive]);

        // A different value is not the one the source described.
        $lab->update(['range_max' => '10']);
        $this->assertSame([true, null], [$lab->refresh()->range_min_inclusive, $lab->range_max_inclusive]);

        // A write that states both keeps what it states, even with a new value.
        $lab->update(['range_min' => '2', 'range_min_inclusive' => true]);
        $this->assertSame([true, null], [$lab->refresh()->range_min_inclusive, $lab->range_max_inclusive]);

        // No bound, nothing to be inclusive.
        $lab->update(['range_min' => null, 'range_max_inclusive' => true, 'range_max' => null]);
        $this->assertSame([null, null], [$lab->refresh()->range_min_inclusive, $lab->range_max_inclusive]);
    }

    public function test_the_agent_api_reads_and_updates_each_bound_inclusiveness(): void
    {
        $this->configurePassportKeys();
        $patient = $this->patient();
        $owner = $patient->owner;
        $this->assertNotNull($owner);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $lab = $this->savedLab($patient, ['range_max' => '5', 'range_max_inclusive' => true]);
        $client = Client::query()->create([
            'name' => 'Synthetic Range Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $url = "/api/v1/patients/{$patient->id}/lab-results/{$lab->id}";

        $version = (string) $this->getJson($url)->assertOk()
            ->assertJsonPath('data.range_min_inclusive', null)
            ->assertJsonPath('data.range_max_inclusive', true)
            ->json('data.version');

        $version = (string) $this->patchJson($url, ['expected_version' => $version, 'data' => ['range_min' => '1', 'range_min_inclusive' => false]])
            ->assertOk()
            ->assertJsonPath('outcome', 'updated')
            ->assertJsonPath('data.range_min_inclusive', false)
            ->assertJsonPath('data.range_max_inclusive', true)
            ->json('version');

        // Changing a bound alone resets what was said of the old value.
        $version = (string) $this->patchJson($url, ['expected_version' => $version, 'data' => ['range_max' => '6']])
            ->assertOk()
            ->assertJsonPath('data.range_min_inclusive', false)
            ->assertJsonPath('data.range_max_inclusive', null)
            ->json('version');
        $this->assertSame($version, app(AgentClinicalRecordVersion::class)->for($lab->refresh()));

        $this->patchJson($url, ['expected_version' => $version, 'data' => ['range_max_inclusive' => 'sometimes']])
            ->assertUnprocessable();
    }

    /**
     * @param  array<string, array{0: string|null, 1: string|null, 2: mixed}>  $shapes
     */
    private function ccda(array $shapes): string
    {
        $components = '';
        $index = 0;
        foreach ($shapes as $analyte => [$low, $high]) {
            $index++;
            $components .= <<<XML
              <component>
                <observation classCode="OBS" moodCode="EVN">
                  <templateId root="2.16.840.1.113883.10.20.22.4.2"/>
                  <id root="2.25.3001" extension="bound-{$index}"/>
                  <code code="SYN-B{$index}" codeSystem="2.25.9" displayName="{$analyte}"/>
                  <statusCode code="completed"/>
                  <effectiveTime value="20260102030405-0800"/>
                  <value xsi:type="PQ" value="3" unit="mg/dL"/>
                  <referenceRange>
                    <observationRange>
                      <value xsi:type="IVL_PQ">{$low}{$high}</value>
                      <interpretationCode code="N" codeSystem="2.16.840.1.113883.5.83"/>
                    </observationRange>
                  </referenceRange>
                </observation>
              </component>

            XML;
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
              <id root="2.25.3000"/>
              <title>Synthetic Health Summary</title>
              <component>
                <structuredBody>
                  <component>
                    <section>
                      <templateId root="2.16.840.1.113883.10.20.22.2.3.1"/>
                      <code code="30954-2" codeSystem="2.16.840.1.113883.6.1"/>
                      <title>Results</title>
                      <text><paragraph>Synthetic results</paragraph></text>
                      <entry typeCode="DRIV">
                        <organizer classCode="BATTERY" moodCode="EVN">
                          <templateId root="2.16.840.1.113883.10.20.22.4.1"/>
                          <id root="2.25.3002"/>
                          <code code="SYN-BOUNDS" codeSystem="2.25.9" displayName="Synthetic bounds panel"/>
                          <statusCode code="completed"/>
                          <effectiveTime value="20260102030405-0800"/>
            {$components}
                        </organizer>
                      </entry>
                    </section>
                  </component>
                </structuredBody>
              </component>
            </ClinicalDocument>
            XML;
    }

    /** @param array<string, mixed> $attributes */
    private function unsavedLab(int $id, string $analyte, array $attributes): PhrLabResult
    {
        $lab = new PhrLabResult(['analyte' => $analyte, 'value' => '3', 'unit' => 'mg/dL', ...$attributes]);
        $lab->id = $id;

        return $lab;
    }

    /** @param array<string, mixed> $attributes */
    private function savedLab(PhrPatient $patient, array $attributes): PhrLabResult
    {
        return PhrLabResult::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'analyte' => 'Synthetic analyte',
            'value' => '3',
            'unit' => 'mg/dL',
            'review_status' => 'confirmed',
            ...$attributes,
        ]);
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = rtrim(rtrim((string) $value, '0'), '.');

        return $string === '' ? '0' : $string;
    }

    private function patient(): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $this->createUser()->id,
            'display_name' => 'Synthetic Patient',
            'relationship' => 'self',
        ]);
    }
}
