<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedication;
use App\Models\PhrMedicationTransition;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Services\PHR\Import\PhrFhirImporter;
use App\Services\PHR\Review\PhrReviewQueueService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * What happened to each home medication during a stay (#414): written over MCP, read back
 * grouped on the encounter, refused when it does not fit the records it names, reviewed,
 * and exported to FHIR (and read back) only when every record it names is exported. Every
 * value is the synthetic inpatient stay of #405; no real data.
 */
final class PhrMedicationTransitionsTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Carbon::setTestNow('2026-03-01 12:00:00');
    }

    public function test_the_synthetic_stays_admission_and_discharge_transitions_are_written_over_mcp_and_read_back_grouped_on_the_encounter(): void
    {
        [, $patient] = $this->mcpSession();
        $stay = $this->stay($patient);

        $written = [];
        foreach ($this->syntheticTransitions($stay) as $externalId => $data) {
            $result = $this->tool('medication_transitions.upsert', ['patient_id' => $patient->id, 'external_id' => $externalId, 'source_document_id' => null, 'expected_version' => null, 'data' => $data]);
            $this->assertFalse($result['isError'] ?? false, json_encode($result));
            $this->assertSame('created', $result['structuredContent']['outcome']);
            $this->assertSame(PhrReviewStatus::PENDING, $result['structuredContent']['data']['review_status']);
            $written[$externalId] = $result['structuredContent'];
        }
        // Idempotent by external id: the same write again changes nothing.
        $again = $this->tool('medication_transitions.upsert', ['patient_id' => $patient->id, 'external_id' => 'synthetic-admission-lisinopril', 'source_document_id' => null, 'expected_version' => null, 'data' => $this->syntheticTransitions($stay)['synthetic-admission-lisinopril']]);
        $this->assertSame('unchanged', $again['structuredContent']['outcome']);
        $this->assertSame($written['synthetic-admission-lisinopril']['version'], $again['structuredContent']['version']);
        $this->assertSame(7, PhrMedicationTransition::query()->where('patient_id', $patient->id)->count());

        $read = $this->tool('clinical.get', ['resource_type' => 'medication-transitions', 'patient_id' => $patient->id, 'record_id' => $written['synthetic-admission-metformin']['data']['id']])['structuredContent']['data'];
        $this->assertSame([...$written['synthetic-admission-metformin']['data'], 'version' => $written['synthetic-admission-metformin']['version']], $read);
        $listed = $this->tool('clinical.list', ['resource_type' => 'medication-transitions', 'patient_id' => $patient->id, 'office_visit_id' => $stay['visit']->id]);
        $this->assertCount(7, $listed['structuredContent']['data']);

        $groups = $this->tool('office_visits.get_stay', ['patient_id' => $patient->id, 'visit_id' => $stay['visit']->id])['structuredContent']['data']['groups'];
        $home = collect($groups['home_medications']['data'])->keyBy('name');
        $this->assertSame(['lisinopril 10 mg oral tablet', 'metformin 500 mg oral tablet', 'acetaminophen 500 mg oral tablet'], $home->keys()->all());
        $summary = static fn (array $medication): array => array_map(static fn (array $transition): string => $transition['phase'].':'.$transition['action'].':'.($transition['order_medication_id'] ?? '-'), $medication['transitions']);
        $this->assertSame(['admission:held:-', 'discharge:continued:'.$stay['lisinopril_discharge']->id], $summary($home['lisinopril 10 mg oral tablet']));
        $this->assertSame(['admission:changed:'.$stay['insulin']->id, 'discharge:continued:'.$stay['metformin_discharge']->id], $summary($home['metformin 500 mg oral tablet']));
        $this->assertSame(['admission:changed:'.$stay['acetaminophen_650']->id], $summary($home['acetaminophen 500 mg oral tablet']));
        $this->assertSame('community', $home['lisinopril 10 mg oral tablet']['category']);
        $this->assertArrayHasKey('version', $home['lisinopril 10 mg oral tablet']['transitions'][0]);
        $new = collect($groups['new_medications']['data']);
        $this->assertSame(['ceftriaxone 1 g IV every 24 hours', 'cefpodoxime 200 mg oral twice daily for 5 days'], $new->pluck('name')->all());
        $this->assertSame([['admission', 'new']], array_map(static fn (array $t): array => [$t['phase'], $t['action']], $new[0]['transitions']));
        $this->assertSame([['discharge', 'new']], array_map(static fn (array $t): array => [$t['phase'], $t['action']], $new[1]['transitions']));

        // One group pages on its own.
        $page = $this->tool('office_visits.get_stay', ['patient_id' => $patient->id, 'visit_id' => $stay['visit']->id, 'group' => 'home_medications', 'limit' => 2])['structuredContent']['data']['groups'];
        $this->assertSame(['home_medications'], array_keys($page));
        $this->assertTrue($page['home_medications']['pagination']['has_more']);

        // A retracted transition leaves the grouped read.
        $retracted = $this->tool('clinical.retract', ['resource_type' => 'medication-transitions', 'patient_id' => $patient->id, 'record_id' => $written['synthetic-admission-acetaminophen']['data']['id'], 'expected_version' => $written['synthetic-admission-acetaminophen']['version']]);
        $this->assertFalse($retracted['isError'] ?? false, json_encode($retracted));
        $after = $this->tool('office_visits.get_stay', ['patient_id' => $patient->id, 'visit_id' => $stay['visit']->id, 'group' => 'home_medications'])['structuredContent']['data']['groups']['home_medications']['data'];
        $this->assertSame(['lisinopril 10 mg oral tablet', 'metformin 500 mg oral tablet'], array_column($after, 'name'));
    }

    public function test_a_transition_that_does_not_fit_the_records_it_names_is_refused(): void
    {
        [, $patient] = $this->restSession([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $stay = $this->stay($patient);
        $other = $this->patient('Other Example');
        $otherMedication = $this->medication($other, 'lisinopril 10 mg oral tablet', 'community');
        $put = fn (array $data): TestResponse => $this->putJson("/api/v1/patients/{$patient->id}/medication-transitions", [
            'external_id' => 'synthetic-refused', 'source_document_id' => null, 'expected_version' => null, 'data' => $data,
        ]);
        $visit = $stay['visit']->id;

        $refusals = [
            'held with an order' => [['office_visit_id' => $visit, 'home_medication_id' => $stay['lisinopril']->id, 'order_medication_id' => $stay['insulin']->id, 'phase' => 'admission', 'action' => 'held'], 'data.order_medication_id'],
            'stopped without the home medication' => [['office_visit_id' => $visit, 'phase' => 'admission', 'action' => 'stopped'], 'data.home_medication_id'],
            'new with a home medication' => [['office_visit_id' => $visit, 'home_medication_id' => $stay['metformin']->id, 'order_medication_id' => $stay['ceftriaxone']->id, 'phase' => 'admission', 'action' => 'new'], 'data.home_medication_id'],
            'changed without an order' => [['office_visit_id' => $visit, 'home_medication_id' => $stay['metformin']->id, 'phase' => 'admission', 'action' => 'changed'], 'data.order_medication_id'],
            'another patient\'s medication' => [['office_visit_id' => $visit, 'home_medication_id' => $otherMedication->id, 'phase' => 'admission', 'action' => 'held'], 'data.home_medication_id'],
            'another patient\'s visit' => [['office_visit_id' => $this->visit($other)->id, 'home_medication_id' => $stay['lisinopril']->id, 'phase' => 'admission', 'action' => 'held'], 'data.office_visit_id'],
            'an inpatient order as the home medication' => [['office_visit_id' => $visit, 'home_medication_id' => $stay['insulin']->id, 'phase' => 'admission', 'action' => 'held'], 'data.home_medication_id'],
            'a discharge medication at admission' => [['office_visit_id' => $visit, 'home_medication_id' => $stay['lisinopril']->id, 'order_medication_id' => $stay['lisinopril_discharge']->id, 'phase' => 'admission', 'action' => 'continued'], 'data.order_medication_id'],
            'an inpatient order at discharge' => [['office_visit_id' => $visit, 'order_medication_id' => $stay['ceftriaxone']->id, 'phase' => 'discharge', 'action' => 'new'], 'data.order_medication_id'],
            'an unknown action' => [['office_visit_id' => $visit, 'home_medication_id' => $stay['lisinopril']->id, 'phase' => 'admission', 'action' => 'paused'], 'data.action'],
        ];
        foreach ($refusals as $case => [$data, $field]) {
            $put($data)->assertUnprocessable()->assertJsonValidationErrors([$field]);
            $this->assertSame(0, PhrMedicationTransition::query()->count(), $case);
        }

        // An update is held to the same rules: its stored row as it would be saved.
        $created = $put(['office_visit_id' => $visit, 'home_medication_id' => $stay['lisinopril']->id, 'phase' => 'admission', 'action' => 'held'])->assertCreated()->json();
        $this->patchJson("/api/v1/patients/{$patient->id}/medication-transitions/{$created['data']['id']}", [
            'expected_version' => $created['version'], 'data' => ['action' => 'continued'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['data.order_medication_id']);

        // The database holds the shape and the patient too, whatever writes the row.
        $row = ['patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'office_visit_id' => $visit, 'phase' => 'admission', 'review_status' => PhrReviewStatus::CONFIRMED, 'created_at' => now(), 'updated_at' => now()];
        foreach ([
            [...$row, 'home_medication_id' => $stay['lisinopril']->id, 'order_medication_id' => $stay['insulin']->id, 'action' => 'held'],
            [...$row, 'order_medication_id' => $stay['ceftriaxone']->id, 'home_medication_id' => $stay['metformin']->id, 'action' => 'new'],
            [...$row, 'home_medication_id' => $stay['metformin']->id, 'action' => 'changed'],
            [...$row, 'home_medication_id' => $otherMedication->id, 'action' => 'held'],
        ] as $index => $invalid) {
            try {
                DB::table('phr_medication_transitions')->insert($invalid);
                $this->fail("Row {$index} should have been refused by the database.");
            } catch (QueryException) {
                $this->assertSame(1, DB::table('phr_medication_transitions')->count());
            }
        }
    }

    public function test_transitions_are_reviewed_like_clinical_records_and_shown_on_the_visit_page(): void
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $stay = $this->stay($patient);
        $held = $this->transition($patient, $stay, ['home_medication_id' => $stay['lisinopril']->id, 'phase' => 'admission', 'action' => 'held', 'note' => 'Held for acute kidney injury', 'review_status' => PhrReviewStatus::PENDING]);

        $this->actingAs($owner);
        $items = collect(app(PhrReviewQueueService::class)->groups($patient))->flatMap(static fn (array $group): array => $group['items'])
            ->where('review_segment', 'medication-transitions')->values();
        $this->assertCount(1, $items);
        $this->assertSame('office-visits', $items[0]['section']);
        $this->assertSame("/phr/patient/{$patient->id}/office-visits/{$stay['visit']->id}", $items[0]['open_path']);
        $this->assertSame('lisinopril 10 mg oral tablet held at admission', $items[0]['title']);
        $this->assertSame(1, app(PhrReviewQueueService::class)->summary($patient)['sections']['medication-transitions']['pending']);

        $reviewed = $this->patchJson("/api/phr/patients/{$patient->id}/medication-transitions/{$held->id}/review", ['review_status' => PhrReviewStatus::CONFIRMED, 'version' => $items[0]['version']])
            ->assertOk()->json('medication_transition');
        $this->assertSame(PhrReviewStatus::CONFIRMED, $reviewed['review_status']);
        $this->assertSame($reviewed, $this->getJson("/api/phr/patients/{$patient->id}/medication-transitions/{$held->id}")->assertOk()->json('medication_transition'));
        $this->assertSame(0, app(PhrReviewQueueService::class)->summary($patient)['sections']['medication-transitions']['pending']);

        $this->getJson("/api/phr/patients/{$patient->id}/office-visits/{$stay['visit']->id}")
            ->assertOk()
            ->assertJsonPath('office_visit.medication_transitions.0.action', 'held')
            ->assertJsonPath('office_visit.medication_transitions.0.home_medication.name', 'lisinopril 10 mg oral tablet')
            ->assertJsonPath('office_visit.medication_transitions.0.order_medication', null)
            ->assertJsonPath('office_visit.medication_transitions.0.note', 'Held for acute kidney injury');
        $this->assertCount(1, $this->getJson("/api/phr/patients/{$patient->id}/medication-transitions?office_visit_id={$stay['visit']->id}")->assertOk()->json('medication_transitions'));
    }

    public function test_fhir_export_references_both_sides_only_when_both_are_exported_and_reads_back_as_the_same_transitions(): void
    {
        $patient = $this->patient();
        $stay = $this->stay($patient);
        foreach ($this->syntheticTransitions($stay) as $externalId => $data) {
            $this->transition($patient, $stay, [...$data, 'external_id' => $externalId, 'import_source' => 'synthetic']);
        }

        $bundle = $this->fhirExport($patient);
        $insulin = $this->exported($bundle, 'MedicationRequest', $stay['insulin']);
        $this->assertSame([['reference' => 'MedicationStatement/phr-medication-'.$stay['metformin']->id]], $insulin['supportingInformation']);
        $this->assertSame([
            ['url' => 'id', 'valueString' => 'phr-medication-transition-'.PhrMedicationTransition::query()->where('external_id', 'synthetic-admission-metformin')->value('id')],
            ['url' => 'phase', 'valueCode' => 'admission'],
            ['url' => 'action', 'valueCode' => 'changed'],
            ['url' => 'encounter', 'valueReference' => ['reference' => 'Encounter/phr-visit-'.$stay['visit']->id]],
            ['url' => 'homeMedication', 'valueReference' => ['reference' => 'MedicationStatement/phr-medication-'.$stay['metformin']->id]],
        ], $insulin['extension'][0]['extension']);
        $this->assertSame(PhrFhirExporter::TRANSITION_EXTENSION, $insulin['extension'][0]['url']);
        $this->assertArrayNotHasKey('priorPrescription', $insulin, 'priorPrescription references a MedicationRequest; a home medication is a MedicationStatement');
        // Held and stopped have no order: they ride on the home medication's statement.
        $lisinopril = $this->exported($bundle, 'MedicationStatement', $stay['lisinopril']);
        $this->assertSame(['admission', 'held'], [$lisinopril['extension'][0]['extension'][1]['valueCode'], $lisinopril['extension'][0]['extension'][2]['valueCode']]);
        $this->assertSame(7, $this->transitionExtensionCount($bundle));

        // Only when every record it names is exported: an unconfirmed home medication takes
        // its transitions out, and an unconfirmed stay takes them all.
        $stay['metformin']->forceFill(['review_status' => PhrReviewStatus::PENDING])->save();
        $partial = $this->fhirExport($patient);
        $this->assertSame(5, $this->transitionExtensionCount($partial));
        $this->assertArrayNotHasKey('extension', $this->exported($partial, 'MedicationRequest', $stay['insulin']));
        $this->assertArrayNotHasKey('supportingInformation', $this->exported($partial, 'MedicationRequest', $stay['insulin']));
        $stay['metformin']->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        PhrMedicationTransition::query()->where('external_id', 'synthetic-admission-ceftriaxone')->update(['review_status' => PhrReviewStatus::PENDING]);
        $this->assertSame(6, $this->transitionExtensionCount($this->fhirExport($patient)));
        $stay['visit']->forceFill(['review_status' => PhrReviewStatus::PENDING])->save();
        $this->assertSame(0, $this->transitionExtensionCount($this->fhirExport($patient)));

        // PHR's own export reads back into another patient as the same transitions, pending review.
        $copy = $this->patient('Pat Example Copy');
        $result = app(PhrFhirImporter::class)->importBundle($bundle, $copy, $copy->owner_user_id);
        $this->assertSame([], array_values(array_filter($result->warnings, static fn (string $warning): bool => str_contains($warning, 'transition'))));
        $this->assertSame($this->transitionShapes($patient), $this->transitionShapes($copy));
        $this->assertSame([PhrReviewStatus::PENDING], PhrMedicationTransition::query()->where('patient_id', $copy->id)->distinct()->pluck('review_status')->all());
        $again = app(PhrFhirImporter::class)->importBundle($bundle, $copy, $copy->owner_user_id);
        $this->assertSame([0, 0], [$again->created, $again->updated]);
        $this->assertSame(7, PhrMedicationTransition::query()->where('patient_id', $copy->id)->count());
    }

    public function test_fhir_import_reads_prior_prescription_and_never_infers_a_transition_from_names(): void
    {
        $patient = $this->patient();
        $encounter = ['reference' => 'Encounter/synthetic-stay'];
        $community = [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/medicationrequest-category', 'code' => 'community']]]];
        $inpatient = [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/medicationrequest-category', 'code' => 'inpatient']]]];
        $discharge = [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/medicationrequest-category', 'code' => 'discharge']]]];
        $request = static fn (string $id, string $text, array $category, string $dose, array $extra = []): array => ['resource' => [
            'resourceType' => 'MedicationRequest', 'id' => $id, 'status' => 'active', 'intent' => 'order', 'category' => $category,
            'medicationCodeableConcept' => ['text' => $text],
            'dosageInstruction' => [['route' => ['text' => 'oral'], 'doseAndRate' => [['doseQuantity' => ['value' => (float) $dose, 'unit' => 'mg']]]]],
            ...$extra,
        ]];
        $bundle = ['resourceType' => 'Bundle', 'type' => 'collection', 'entry' => [
            ['resource' => ['resourceType' => 'Encounter', 'id' => 'synthetic-stay', 'status' => 'in-progress', 'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => 'IMP'], 'period' => ['start' => '2026-02-09T14:20:00-08:00']]],
            $request('home-acetaminophen', 'acetaminophen', $community, '500'),
            $request('home-lisinopril', 'lisinopril', $community, '10'),
            $request('home-metformin', 'metformin', $community, '500'),
            // Changed: its prior prescription is the home acetaminophen at another dose.
            $request('order-acetaminophen', 'acetaminophen', $inpatient, '650', ['priorPrescription' => ['reference' => 'MedicationRequest/home-acetaminophen'], 'encounter' => $encounter]),
            // Continued at discharge: the same drug and dose.
            $request('discharge-lisinopril', 'lisinopril', $discharge, '10', ['basedOn' => [['reference' => 'MedicationRequest/home-lisinopril']], 'encounter' => $encounter]),
            // The same name as a home medication but no link: never a transition.
            $request('discharge-metformin', 'metformin', $discharge, '500', ['encounter' => $encounter]),
            // A link whose stay is not in the bundle: not guessed at.
            $request('order-lisinopril-elsewhere', 'lisinopril', $inpatient, '10', ['priorPrescription' => ['reference' => 'MedicationRequest/home-lisinopril'], 'encounter' => ['reference' => 'Encounter/other-stay']]),
        ]];

        $result = app(PhrFhirImporter::class)->importBundle($bundle, $patient, $patient->owner_user_id);

        $names = PhrMedication::query()->where('patient_id', $patient->id)->pluck('external_id', 'id');
        $shapes = PhrMedicationTransition::query()->where('patient_id', $patient->id)->orderBy('id')->get()
            ->map(static fn (PhrMedicationTransition $t): array => [$t->phase, $t->action, $names[$t->home_medication_id], $names[$t->order_medication_id]])->all();
        $this->assertSame([
            ['admission', 'changed', 'MedicationRequest/home-acetaminophen', 'inpatient:MedicationRequest/order-acetaminophen'],
            ['discharge', 'continued', 'MedicationRequest/home-lisinopril', 'discharge:MedicationRequest/discharge-lisinopril'],
        ], $shapes);
        $this->assertStringContainsString('1 medication transition(s)', implode("\n", $result->warnings));
    }

    /**
     * The synthetic stay's transitions: lisinopril held, metformin changed to insulin lispro,
     * acetaminophen's dose changed and ceftriaxone new at admission; lisinopril and metformin
     * continued and cefpodoxime new at discharge.
     *
     * @param  array<string, PhrOfficeVisit|PhrMedication>  $stay
     * @return array<string, array<string, mixed>>
     */
    private function syntheticTransitions(array $stay): array
    {
        $visit = $stay['visit']->id;

        return [
            'synthetic-admission-lisinopril' => ['office_visit_id' => $visit, 'home_medication_id' => $stay['lisinopril']->id, 'order_medication_id' => null, 'phase' => 'admission', 'action' => 'held', 'note' => 'Held for acute kidney injury'],
            'synthetic-admission-metformin' => ['office_visit_id' => $visit, 'home_medication_id' => $stay['metformin']->id, 'order_medication_id' => $stay['insulin']->id, 'phase' => 'admission', 'action' => 'changed'],
            'synthetic-admission-acetaminophen' => ['office_visit_id' => $visit, 'home_medication_id' => $stay['acetaminophen']->id, 'order_medication_id' => $stay['acetaminophen_650']->id, 'phase' => 'admission', 'action' => 'changed'],
            'synthetic-admission-ceftriaxone' => ['office_visit_id' => $visit, 'home_medication_id' => null, 'order_medication_id' => $stay['ceftriaxone']->id, 'phase' => 'admission', 'action' => 'new'],
            'synthetic-discharge-lisinopril' => ['office_visit_id' => $visit, 'home_medication_id' => $stay['lisinopril']->id, 'order_medication_id' => $stay['lisinopril_discharge']->id, 'phase' => 'discharge', 'action' => 'continued'],
            'synthetic-discharge-metformin' => ['office_visit_id' => $visit, 'home_medication_id' => $stay['metformin']->id, 'order_medication_id' => $stay['metformin_discharge']->id, 'phase' => 'discharge', 'action' => 'continued'],
            'synthetic-discharge-cefpodoxime' => ['office_visit_id' => $visit, 'home_medication_id' => null, 'order_medication_id' => $stay['cefpodoxime']->id, 'phase' => 'discharge', 'action' => 'new'],
        ];
    }

    /**
     * The synthetic stay, its home medications, inpatient orders and discharge list, all
     * confirmed.
     *
     * @return array<string, PhrOfficeVisit|PhrMedication>
     */
    private function stay(PhrPatient $patient): array
    {
        return [
            'visit' => $this->visit($patient),
            'lisinopril' => $this->medication($patient, 'lisinopril 10 mg oral tablet', 'community'),
            'metformin' => $this->medication($patient, 'metformin 500 mg oral tablet', 'community'),
            'acetaminophen' => $this->medication($patient, 'acetaminophen 500 mg oral tablet', 'community'),
            'insulin' => $this->medication($patient, 'insulin lispro sliding scale', 'inpatient'),
            'ceftriaxone' => $this->medication($patient, 'ceftriaxone 1 g IV every 24 hours', 'inpatient'),
            'acetaminophen_650' => $this->medication($patient, 'acetaminophen 650 mg oral every 6 hours as needed', 'inpatient'),
            'lisinopril_discharge' => $this->medication($patient, 'lisinopril 10 mg oral tablet (resume)', 'discharge'),
            'metformin_discharge' => $this->medication($patient, 'metformin 500 mg oral tablet (resume)', 'discharge'),
            'cefpodoxime' => $this->medication($patient, 'cefpodoxime 200 mg oral twice daily for 5 days', 'discharge'),
        ];
    }

    /**
     * @param  array<string, PhrOfficeVisit|PhrMedication>  $stay
     * @param  array<string, mixed>  $attributes
     */
    private function transition(PhrPatient $patient, array $stay, array $attributes): PhrMedicationTransition
    {
        return PhrMedicationTransition::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'office_visit_id' => $stay['visit']->id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            ...$attributes,
        ]);
    }

    private function medication(PhrPatient $patient, string $name, string $category): PhrMedication
    {
        return PhrMedication::query()->create([
            'patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'name' => $name,
            'status' => 'active', 'category' => $category, 'review_status' => PhrReviewStatus::CONFIRMED,
        ]);
    }

    private function visit(PhrPatient $patient): PhrOfficeVisit
    {
        return PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id, 'user_id' => $patient->owner_user_id, 'visit_date' => '2026-02-09',
            'visit_type' => 'Synthetic hospital admission', 'encounter_class' => 'IMP', 'facility_name' => 'Example General Hospital',
            'review_status' => PhrReviewStatus::CONFIRMED,
        ]);
    }

    /** @return list<array{string, string, string|null, string|null, string|null}> each transition by the names it gives */
    private function transitionShapes(PhrPatient $patient): array
    {
        $names = PhrMedication::query()->where('patient_id', $patient->id)->pluck('name', 'id');

        return PhrMedicationTransition::query()->where('patient_id', $patient->id)->get()
            ->map(static fn (PhrMedicationTransition $t): array => [$t->phase, $t->action, $names[$t->home_medication_id] ?? null, $names[$t->order_medication_id] ?? null, $t->note])
            ->sortBy(static fn (array $shape): string => implode('|', array_map('strval', $shape)))->values()->all();
    }

    /** @param  array<string, mixed>  $bundle */
    private function transitionExtensionCount(array $bundle): int
    {
        $count = 0;
        foreach ($bundle['entry'] as $entry) {
            foreach ($entry['resource']['extension'] ?? [] as $extension) {
                $count += $extension['url'] === PhrFhirExporter::TRANSITION_EXTENSION ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function exported(array $bundle, string $type, PhrMedication $medication): array
    {
        foreach ($bundle['entry'] as $entry) {
            if ($entry['resource']['resourceType'] === $type && $entry['resource']['id'] === 'phr-medication-'.$medication->id) {
                return $entry['resource'];
            }
        }
        $this->fail("No {$type} for medication {$medication->id}.");
    }

    /** @return array<string, mixed> */
    private function fhirExport(PhrPatient $patient): array
    {
        return json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function mcpSession(): array
    {
        [$owner, $patient] = $this->restSession([AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');

        return [$owner, $patient];
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: User, 1: PhrPatient}
     */
    private function restSession(array $scopes): array
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $client = Client::query()->create([
            'name' => 'Synthetic Transition Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($owner, $scopes, 'api', $client);

        return [$owner, $patient];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $tool, array $arguments): array
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);

        return $response->json('result') ?? $this->fail('No result: '.$response->getContent());
    }

    /** @param  array<string, mixed>  $message */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers)->assertOk();
    }

    private function patient(string $name = 'Pat Example'): PhrPatient
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id, 'display_name' => $name, 'relationship' => 'self', 'birth_date' => '1950-06-15',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        return $patient;
    }
}
