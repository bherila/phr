<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use App\Models\PhrPatient;
use App\Models\PhrPatientVital;
use Tests\TestCase;

class PhrSectionTest extends TestCase
{
    public function test_phr_page_requires_login(): void
    {
        $this->get('/phr')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_phr_page(): void
    {
        $response = $this->actingAs($this->createUser())->get('/phr');

        $response->assertRedirect('/phr/patients');
    }

    public function test_authenticated_user_can_open_phr_section_pages(): void
    {
        $this->withoutVite();
        $user = $this->createUser();

        $this->actingAs($user)->get('/phr/patients')->assertOk();
        $this->actingAs($user)->get('/phr/patients/manage')->assertOk();
        $this->actingAs($user)->get('/phr/imports')->assertOk();
        $this->actingAs($user)->get('/phr/config')->assertOk();
    }

    public function test_patient_page_requires_patient_access(): void
    {
        $this->withoutVite();
        $owner = $this->createUser();
        $other = $this->createUser();

        $patientResponse = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Test Patient',
            'relationship' => 'self',
        ]);
        $patientResponse->assertCreated();
        $patientId = (int) $patientResponse->json('patient.id');

        $this->actingAs($owner)->get("/phr/patient/{$patientId}")->assertOk();
        $this->actingAs($other)->get("/phr/patient/{$patientId}")->assertNotFound();
    }

    public function test_patient_api_supports_owner_manager_viewer_and_unshared_access(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $viewer = $this->createUser();
        $other = $this->createUser();

        $patientResponse = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Primary',
            'relationship' => 'self',
        ]);

        $patientResponse->assertCreated()->assertJsonPath('patient.display_name', 'Primary');
        $patientId = (int) $patientResponse->json('patient.id');

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $manager->email,
            'access_level' => 'manager',
        ])->assertCreated();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $viewer->email,
            'access_level' => 'viewer',
        ])->assertCreated();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/lab-results", [
            'test_name' => 'Metabolic panel',
            'analyte' => 'Glucose',
            'value' => '95',
            'value_numeric' => 95,
            'unit' => 'mg/dL',
        ])->assertCreated()->assertJsonPath('lab_result.user_id', $owner->id);

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/vitals", [
            'vital_name' => 'Blood Pressure',
            'vital_date' => '2026-05-16',
            'vital_value' => '120/80',
            'value_numeric' => 120,
            'value_numeric_secondary' => 80,
            'unit' => 'mmHg',
        ])->assertCreated()->assertJsonPath('vital.user_id', $owner->id);

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/lab-results")
            ->assertOk()
            ->assertJsonCount(1, 'lab_results');

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/vitals", [
            'vital_name' => 'Pulse',
        ])->assertForbidden();

        $this->actingAs($other)->getJson("/api/phr/patients/{$patientId}/vitals")->assertNotFound();

        $this->assertSame(1, PhrPatient::query()->where('owner_user_id', $owner->id)->count());
        $this->assertSame(1, PhrLabResult::query()->where('patient_id', $patientId)->count());
        $this->assertSame(1, PhrPatientVital::query()->where('patient_id', $patientId)->count());
    }
}
