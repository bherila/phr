<?php

namespace Tests\Feature\PHR;

use Tests\TestCase;

class PhrClinicalDataTest extends TestCase
{
    private function createPatientWithAccess(): array
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $viewer = $this->createUser();

        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Test Patient',
        ])->assertCreated()->json('patient.id');

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $manager->email,
            'access_level' => 'manager',
        ])->assertCreated();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $viewer->email,
            'access_level' => 'viewer',
        ])->assertCreated();

        return compact('owner', 'manager', 'viewer', 'patientId');
    }

    // ── Lab Results ───────────────────────────────────────────────────────────

    public function test_manager_can_create_show_update_and_delete_lab_result(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $labResultId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/lab-results", [
            'test_name' => 'CBC',
            'analyte' => 'Hemoglobin',
            'value' => '13.2',
            'unit' => 'g/dL',
        ])->assertCreated()
            ->assertJsonPath('lab_result.analyte', 'Hemoglobin')
            ->json('lab_result.id');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/lab-results/{$labResultId}")
            ->assertOk()
            ->assertJsonPath('lab_result.test_name', 'CBC');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/lab-results/{$labResultId}", [
            'analyte' => 'Hemoglobin',
            'value' => '14.1',
        ])->assertOk()
            ->assertJsonPath('lab_result.value', '14.1');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/lab-results/{$labResultId}")->assertNoContent();
    }

    // ── Vitals ────────────────────────────────────────────────────────────────

    public function test_manager_can_create_show_update_and_delete_vital(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $vitalId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/vitals", [
            'vital_name' => 'Heart Rate',
            'vital_value' => '72',
            'unit' => 'bpm',
        ])->assertCreated()
            ->assertJsonPath('vital.vital_name', 'Heart Rate')
            ->json('vital.id');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/vitals/{$vitalId}")
            ->assertOk()
            ->assertJsonPath('vital.unit', 'bpm');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/vitals/{$vitalId}", [
            'vital_name' => 'Heart Rate',
            'vital_value' => '75',
            'unit' => 'bpm',
        ])->assertOk()
            ->assertJsonPath('vital.vital_value', '75');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/vitals/{$vitalId}")->assertNoContent();
    }

    public function test_manager_can_fetch_vital_trend_by_metric_key(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/vitals", [
            'vital_name' => 'Blood Pressure',
            'vital_date' => '2026-01-01',
            'value_numeric' => 120,
            'value_numeric_secondary' => 80,
            'unit' => 'mmHg',
            'secondary_unit' => 'mmHg',
        ])->assertCreated();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/vitals", [
            'vital_name' => 'Blood Pressure',
            'vital_date' => '2026-01-10',
            'value_numeric' => 125,
            'value_numeric_secondary' => 82,
            'unit' => 'mmHg',
            'secondary_unit' => 'mmHg',
        ])->assertCreated();

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/vitals/trend/systolic_bp")
            ->assertOk()
            ->assertJsonPath('metric_key', 'systolic_bp')
            ->assertJsonPath('metric_label', 'Systolic BP')
            ->assertJsonPath('unit', 'mmHg')
            ->assertJsonCount(2, 'points');
    }

    public function test_vital_trend_returns_not_found_for_unknown_metric_key(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/vitals", [
            'vital_name' => 'Heart Rate',
            'vital_value' => '72',
            'unit' => 'bpm',
        ])->assertCreated();

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/vitals/trend/not_a_metric")->assertNotFound();
    }

    // ── Office Visits ──────────────────────────────────────────────────────────

    public function test_manager_can_create_and_list_office_visits(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/office-visits", [
            'visit_date' => '2026-01-15',
            'visit_type' => 'Office',
            'provider_name' => 'Dr. Smith',
            'chief_complaint' => 'Annual checkup',
        ])->assertCreated()->assertJsonPath('office_visit.chief_complaint', 'Annual checkup');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/office-visits")
            ->assertOk()
            ->assertJsonCount(1, 'office_visits');
    }

    public function test_viewer_cannot_create_office_visit(): void
    {
        ['viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/office-visits", [
            'visit_date' => '2026-01-15',
        ])->assertForbidden();
    }

    public function test_manager_can_update_and_delete_office_visit(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $visitId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/office-visits", [
            'visit_date' => '2026-01-15',
            'chief_complaint' => 'Cough',
        ])->assertCreated()->json('office_visit.id');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/office-visits/{$visitId}", [
            'chief_complaint' => 'Cough updated',
        ])->assertOk()->assertJsonPath('office_visit.chief_complaint', 'Cough updated');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/office-visits/{$visitId}")->assertNoContent();

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/office-visits")
            ->assertOk()
            ->assertJsonCount(0, 'office_visits');
    }

    // ── Medications ───────────────────────────────────────────────────────────

    public function test_manager_can_create_and_list_medications(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/medications", [
            'name' => 'Metformin',
            'dose' => '500',
            'dose_unit' => 'mg',
            'frequency' => 'BID',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('medication.name', 'Metformin');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/medications")
            ->assertOk()
            ->assertJsonCount(1, 'medications');
    }

    public function test_viewer_cannot_create_medication(): void
    {
        ['viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/medications", [
            'name' => 'Aspirin',
        ])->assertForbidden();
    }

    public function test_manager_can_update_and_delete_medication(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $medId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/medications", [
            'name' => 'Aspirin',
            'status' => 'active',
        ])->assertCreated()->json('medication.id');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/medications/{$medId}", [
            'status' => 'discontinued',
        ])->assertOk()->assertJsonPath('medication.status', 'discontinued');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/medications/{$medId}")->assertNoContent();
    }

    // ── Conditions ────────────────────────────────────────────────────────────

    public function test_manager_can_create_and_list_conditions(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/conditions", [
            'name' => 'Type 2 Diabetes',
            'icd10_code' => 'E11.9',
            'clinical_status' => 'active',
        ])->assertCreated()->assertJsonPath('condition.name', 'Type 2 Diabetes');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/conditions")
            ->assertOk()
            ->assertJsonCount(1, 'conditions');
    }

    public function test_viewer_cannot_create_condition(): void
    {
        ['viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/conditions", [
            'name' => 'Hypertension',
        ])->assertForbidden();
    }

    public function test_manager_can_update_and_delete_condition(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $condId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/conditions", [
            'name' => 'Hypertension',
            'clinical_status' => 'active',
            'raw_text' => 'HTN on problem list',
        ])->assertCreated()->assertJsonPath('condition.raw_text', 'HTN on problem list')->json('condition.id');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/conditions/{$condId}")
            ->assertOk()
            ->assertJsonPath('condition.name', 'Hypertension');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/conditions/{$condId}", [
            'clinical_status' => 'resolved',
            'abated_date' => '2026-05-18',
        ])->assertOk()
            ->assertJsonPath('condition.clinical_status', 'resolved')
            ->assertJsonPath('condition.abated_date', '2026-05-18');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/conditions/{$condId}")->assertNoContent();
    }

    // ── Procedures ────────────────────────────────────────────────────────────

    public function test_manager_can_create_and_list_procedures(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/procedures", [
            'name' => 'Appendectomy',
            'cpt_code' => '44950',
            'status' => 'completed',
        ])->assertCreated()->assertJsonPath('procedure.name', 'Appendectomy');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/procedures")
            ->assertOk()
            ->assertJsonCount(1, 'procedures');
    }

    public function test_viewer_cannot_create_procedure(): void
    {
        ['viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/procedures", [
            'name' => 'Biopsy',
        ])->assertForbidden();
    }

    public function test_manager_can_update_and_delete_procedure(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $procId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/procedures", [
            'name' => 'Colonoscopy',
            'status' => 'completed',
            'raw_text' => 'Colonoscopy completed',
        ])->assertCreated()->assertJsonPath('procedure.raw_text', 'Colonoscopy completed')->json('procedure.id');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/procedures/{$procId}")
            ->assertOk()
            ->assertJsonPath('procedure.name', 'Colonoscopy');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/procedures/{$procId}", [
            'outcome' => 'Normal findings',
        ])->assertOk()->assertJsonPath('procedure.outcome', 'Normal findings');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/procedures/{$procId}")->assertNoContent();
    }

    // ── Immunizations ─────────────────────────────────────────────────────────

    public function test_manager_can_create_and_list_immunizations(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/immunizations", [
            'vaccine_name' => 'Influenza',
            'administered_on' => '2026-10-01',
            'lot_number' => 'ABC123',
        ])->assertCreated()->assertJsonPath('immunization.vaccine_name', 'Influenza');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/immunizations")
            ->assertOk()
            ->assertJsonCount(1, 'immunizations');
    }

    public function test_viewer_cannot_create_immunization(): void
    {
        ['viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/immunizations", [
            'vaccine_name' => 'COVID-19',
        ])->assertForbidden();
    }

    public function test_manager_can_update_and_delete_immunization(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $immunId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/immunizations", [
            'vaccine_name' => 'Hepatitis B',
            'dose_number' => 1,
            'series_doses' => 3,
            'raw_text' => 'Hep B dose 1',
        ])->assertCreated()->assertJsonPath('immunization.raw_text', 'Hep B dose 1')->json('immunization.id');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/immunizations/{$immunId}")
            ->assertOk()
            ->assertJsonPath('immunization.vaccine_name', 'Hepatitis B');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/immunizations/{$immunId}", [
            'dose_number' => 2,
        ])->assertOk()->assertJsonPath('immunization.dose_number', 2);

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/immunizations/{$immunId}")->assertNoContent();
    }

    // ── Allergies ─────────────────────────────────────────────────────────────

    public function test_manager_can_create_and_list_allergies(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/allergies", [
            'substance' => 'Penicillin',
            'criticality' => 'high',
            'reaction' => 'Anaphylaxis',
            'clinical_status' => 'active',
        ])->assertCreated()->assertJsonPath('allergy.substance', 'Penicillin');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/allergies")
            ->assertOk()
            ->assertJsonCount(1, 'allergies');
    }

    public function test_viewer_cannot_create_allergy(): void
    {
        ['viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/allergies", [
            'substance' => 'Shellfish',
        ])->assertForbidden();
    }

    public function test_manager_can_update_and_delete_allergy(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $allergyId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/allergies", [
            'substance' => 'Sulfa',
            'clinical_status' => 'active',
            'raw_text' => 'Sulfa allergy',
        ])->assertCreated()->assertJsonPath('allergy.raw_text', 'Sulfa allergy')->json('allergy.id');

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertOk()
            ->assertJsonPath('allergy.substance', 'Sulfa');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}", [
            'clinical_status' => 'inactive',
        ])->assertOk()->assertJsonPath('allergy.clinical_status', 'inactive');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")->assertNoContent();
    }

    public function test_unshared_user_cannot_access_any_clinical_data(): void
    {
        ['patientId' => $patientId] = $this->createPatientWithAccess();
        $other = $this->createUser();

        foreach (['lab-results', 'vitals', 'office-visits', 'medications', 'conditions', 'procedures', 'immunizations', 'allergies'] as $endpoint) {
            $this->actingAs($other)->getJson("/api/phr/patients/{$patientId}/{$endpoint}")->assertNotFound();
        }
    }

    public function test_viewer_can_read_but_cannot_modify_clinical_records(): void
    {
        ['manager' => $manager, 'viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $conditionId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/conditions", [
            'name' => 'Asthma',
            'clinical_status' => 'active',
        ])->assertCreated()->json('condition.id');

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/conditions")
            ->assertOk()
            ->assertJsonCount(1, 'conditions');

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/conditions/{$conditionId}")
            ->assertOk()
            ->assertJsonPath('condition.name', 'Asthma');

        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patientId}/conditions/{$conditionId}", [
            'clinical_status' => 'resolved',
        ])->assertForbidden();

        $this->actingAs($viewer)->deleteJson("/api/phr/patients/{$patientId}/conditions/{$conditionId}")
            ->assertForbidden();
    }

    public function test_clinical_record_requests_reject_invalid_enum_values(): void
    {
        ['manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/conditions", [
            'name' => 'Hypertension',
            'clinical_status' => 'bogus',
            'verification_status' => 'bogus',
            'severity' => 'bogus',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['clinical_status', 'verification_status', 'severity']);

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/procedures", [
            'name' => 'Biopsy',
            'status' => 'planned',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/allergies", [
            'substance' => 'Penicillin',
            'category' => 'drug',
            'criticality' => 'unknown',
            'clinical_status' => 'active',
            'verification_status' => 'suspected',
            'severity' => 'fatal',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'criticality', 'verification_status', 'severity']);
    }
}
