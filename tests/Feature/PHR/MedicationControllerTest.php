<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedication;
use App\Models\User;
use Tests\TestCase;

class MedicationControllerTest extends TestCase
{
    /**
     * @return array{owner: User, manager: User, viewer: User, patientId: int}
     */
    private function createPatientWithAccess(): array
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $viewer = $this->createUser();

        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Medication Patient',
            'relationship' => 'self',
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

    public function test_owner_can_create_show_update_and_delete_medication(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $createResponse = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medications", [
            'name' => 'Metformin',
            'dose' => '500',
            'dose_unit' => 'mg',
            'frequency' => 'BID',
            'status' => 'active',
            'raw_text' => 'Metformin 500 mg BID',
        ]);

        $createResponse->assertCreated();
        $medicationId = (int) $createResponse->json('medication.id');
        $createResponse->assertJsonPath('medication.raw_text', 'Metformin 500 mg BID');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/medications/{$medicationId}")
            ->assertOk()
            ->assertJsonPath('medication.name', 'Metformin');

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/medications/{$medicationId}", [
            'status' => 'discontinued',
            'ended_on' => '2026-05-17',
            'reason_for_use' => 'Type 2 diabetes',
        ])->assertOk()
            ->assertJsonPath('medication.status', 'discontinued')
            ->assertJsonPath('medication.ended_on', '2026-05-17')
            ->assertJsonPath('medication.reason_for_use', 'Type 2 diabetes');

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/medications/{$medicationId}")
            ->assertNoContent();

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/medications/{$medicationId}")
            ->assertNotFound();
    }

    public function test_an_end_date_that_has_arrived_ends_the_medication_whatever_status_was_sent(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();
        $url = "/api/phr/patients/{$patientId}/medications";
        $today = now()->toDateString();
        $past = now()->subDay()->toDateString();
        $ahead = now()->addDay()->toDateString();

        foreach ([
            'active, ended' => ['active', $past, 'completed'],
            'active, ends today' => ['active', $today, 'completed'],
            'on hold, ended' => ['on_hold', $past, 'completed'],
            'a finer ended status is kept' => ['discontinued', $past, 'discontinued'],
            'still running until a later date' => ['active', $ahead, 'active'],
            'no end date' => ['on_hold', null, 'on_hold'],
        ] as $case => [$sent, $endedOn, $expected]) {
            $this->actingAs($owner)->postJson($url, ['name' => "Synthetic {$case}", 'status' => $sent, 'ended_on' => $endedOn])
                ->assertCreated()
                ->assertJsonPath('medication.status', $expected);
        }

        // Taking the date away restores what was stored; a date that passes takes effect by itself.
        $id = (int) $this->actingAs($owner)->postJson($url, ['name' => 'Synthetic course', 'ended_on' => $ahead])->json('medication.id');
        $this->travel(2)->days();
        $this->actingAs($owner)->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('medication.status', 'completed');
        $this->actingAs($owner)->patchJson("{$url}/{$id}", ['ended_on' => null])->assertOk()->assertJsonPath('medication.status', 'active');
    }

    public function test_index_only_returns_medications_for_requested_patient(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $otherPatientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Second Patient',
            'relationship' => 'child',
        ])->assertCreated()->json('patient.id');

        PhrMedication::factory()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'name' => 'Visible Medication',
        ]);

        PhrMedication::factory()->create([
            'patient_id' => $otherPatientId,
            'user_id' => $owner->id,
            'name' => 'Hidden Medication',
        ]);

        $response = $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/medications");

        $response->assertOk();
        $response->assertJsonCount(1, 'medications');
        $response->assertJsonPath('medications.0.name', 'Visible Medication');
    }

    public function test_manager_can_manage_medications(): void
    {
        ['owner' => $owner, 'manager' => $manager, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $medication = PhrMedication::factory()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'name' => 'Lisinopril',
            'status' => 'active',
        ]);

        $this->actingAs($manager)->getJson("/api/phr/patients/{$patientId}/medications/{$medication->id}")
            ->assertOk()
            ->assertJsonPath('medication.name', 'Lisinopril');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/medications/{$medication->id}", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('medication.status', 'completed');

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}/medications/{$medication->id}")
            ->assertNoContent();
    }

    public function test_viewer_can_read_but_cannot_modify_medications(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $medication = PhrMedication::factory()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'name' => 'Aspirin',
        ]);

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/medications")
            ->assertOk()
            ->assertJsonCount(1, 'medications');

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/medications/{$medication->id}")
            ->assertOk()
            ->assertJsonPath('medication.name', 'Aspirin');

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/medications", [
            'name' => 'Blocked',
        ])->assertForbidden();

        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patientId}/medications/{$medication->id}", [
            'status' => 'completed',
        ])->assertForbidden();

        $this->actingAs($viewer)->deleteJson("/api/phr/patients/{$patientId}/medications/{$medication->id}")
            ->assertForbidden();
    }

    public function test_unshared_user_cannot_access_medications(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();
        $other = $this->createUser();

        $medication = PhrMedication::factory()->create([
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'name' => 'Atorvastatin',
        ]);

        $this->actingAs($other)->getJson("/api/phr/patients/{$patientId}/medications")->assertNotFound();
        $this->actingAs($other)->getJson("/api/phr/patients/{$patientId}/medications/{$medication->id}")->assertNotFound();
        $this->actingAs($other)->patchJson("/api/phr/patients/{$patientId}/medications/{$medication->id}", [
            'status' => 'completed',
        ])->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/phr/patients/{$patientId}/medications/{$medication->id}")->assertNotFound();
    }

    public function test_medication_routes_require_authentication(): void
    {
        $this->getJson('/api/phr/patients/1/medications')->assertUnauthorized();
        $this->postJson('/api/phr/patients/1/medications', ['name' => 'Aspirin'])->assertUnauthorized();
        $this->getJson('/api/phr/patients/1/medications/1')->assertUnauthorized();
        $this->patchJson('/api/phr/patients/1/medications/1', ['status' => 'completed'])->assertUnauthorized();
        $this->deleteJson('/api/phr/patients/1/medications/1')->assertUnauthorized();
    }

    public function test_create_requires_name_and_valid_status(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/medications", [
            'status' => 'bogus',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'status']);
    }
}
