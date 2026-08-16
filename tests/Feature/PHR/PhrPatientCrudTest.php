<?php

namespace Tests\Feature\PHR;

use Tests\TestCase;

class PhrPatientCrudTest extends TestCase
{
    // ── CREATE ──────────────────────────────────────────────────────────────────

    public function test_owner_can_create_patient(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->postJson('/api/phr/patients', [
            'display_name' => 'Alice',
            'relationship' => 'self',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('patient.display_name', 'Alice');
        $response->assertJsonPath('patient.relationship', 'self');
        $response->assertJsonPath('patient.access_level', 'owner');
        $response->assertJsonPath('patient.can_manage', true);
        $response->assertJsonPath('patient.can_share', true);
    }

    public function test_create_requires_display_name(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/api/phr/patients', [])->assertUnprocessable();
    }

    public function test_create_requires_authentication(): void
    {
        $this->postJson('/api/phr/patients', ['display_name' => 'Alice'])->assertUnauthorized();
    }

    // ── READ ─────────────────────────────────────────────────────────────────────

    public function test_owner_sees_their_patients(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();

        $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice']);
        $this->actingAs($other)->postJson('/api/phr/patients', ['display_name' => 'Bob']);

        $response = $this->actingAs($owner)->getJson('/api/phr/patients');

        $response->assertOk();
        $names = collect($response->json('patients'))->pluck('display_name');
        $this->assertTrue($names->contains('Alice'));
        $this->assertFalse($names->contains('Bob'));
    }

    // ── UPDATE ───────────────────────────────────────────────────────────────────

    public function test_owner_can_update_patient(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice'])->json('patient.id');

        $response = $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}", [
            'display_name' => 'Alice Updated',
            'relationship' => 'spouse',
        ]);

        $response->assertOk();
        $response->assertJsonPath('patient.display_name', 'Alice Updated');
        $response->assertJsonPath('patient.relationship', 'spouse');
    }

    public function test_update_requires_display_name(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice'])->json('patient.id');

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}", [])->assertUnprocessable();
    }

    public function test_viewer_cannot_update_patient(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice'])->json('patient.id');

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $viewer->email,
            'access_level' => 'viewer',
        ]);

        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patientId}", ['display_name' => 'Hacked'])->assertForbidden();
    }

    public function test_unshared_user_cannot_update_patient(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice'])->json('patient.id');

        $this->actingAs($other)->patchJson("/api/phr/patients/{$patientId}", ['display_name' => 'Hacked'])->assertNotFound();
    }

    // ── DELETE ───────────────────────────────────────────────────────────────────

    public function test_owner_can_delete_patient(): void
    {
        $owner = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice'])->json('patient.id');
        $digest = (string) $this->actingAs($owner)
            ->getJson("/api/phr/data-hub/patients/{$patientId}/deletion-preview")
            ->json('deletion_preview.preview_digest');

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}", [
            'confirmation' => 'DELETE',
            'preview_digest' => $digest,
        ])->assertAccepted();

        $this->actingAs($owner)->getJson('/api/phr/patients')->assertJsonCount(0, 'patients');
    }

    public function test_non_owner_cannot_delete_patient(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice'])->json('patient.id');

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $manager->email,
            'access_level' => 'manager',
        ]);

        $this->actingAs($manager)->deleteJson("/api/phr/patients/{$patientId}")->assertForbidden();
    }

    public function test_unshared_user_cannot_delete_patient(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $patientId = (int) $this->actingAs($owner)->postJson('/api/phr/patients', ['display_name' => 'Alice'])->json('patient.id');

        $this->actingAs($other)->deleteJson("/api/phr/patients/{$patientId}")->assertNotFound();
    }

    // ── MANAGE PAGE ──────────────────────────────────────────────────────────────

    public function test_manage_patients_page_renders_for_authenticated_user(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->createUser())->get('/phr/patients/manage');

        $response->assertOk();
        $response->assertViewIs('phr.shell');
        $response->assertSee('data-active-section="manage-patients"', false);
        $response->assertSee('PhrShell');
    }
}
