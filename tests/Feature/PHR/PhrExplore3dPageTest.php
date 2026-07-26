<?php

namespace Tests\Feature\PHR;

use App\Models\User;
use Tests\TestCase;

class PhrExplore3dPageTest extends TestCase
{
    private function createPatientFor(User $owner): int
    {
        $response = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Primary',
            'relationship' => 'self',
        ]);

        return (int) $response->json('patient.id');
    }

    public function test_explore_3d_page_renders_for_authorized_user(): void
    {
        $this->withoutVite();
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $response = $this->actingAs($owner)->get("/phr/patient/{$patientId}/imaging/series/42/explore-3d");

        $response->assertOk();
        $response->assertViewIs('phr.explore3d');
        $response->assertSee('explore3d-root');
        $response->assertSee("data-patient-id=\"{$patientId}\"", false);
        $response->assertSee('data-series-id="42"', false);
    }

    public function test_explore_3d_page_is_not_accessible_to_unshared_user(): void
    {
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $this->actingAs($otherUser)
            ->get("/phr/patient/{$patientId}/imaging/series/42/explore-3d")
            ->assertNotFound();
    }

    public function test_explore_3d_page_requires_authentication(): void
    {
        $this->get('/phr/patient/1/imaging/series/42/explore-3d')->assertRedirect();
    }
}
