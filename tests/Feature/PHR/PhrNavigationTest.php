<?php

namespace Tests\Feature\PHR;

use App\Http\Controllers\PHR\PageController;
use App\Models\User;
use Tests\TestCase;

class PhrNavigationTest extends TestCase
{
    public function test_patients_page_renders_for_authenticated_user(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->createUser())->get('/phr/patients');

        $response->assertOk();
        $response->assertViewIs('phr.shell');
        $response->assertSee('PhrShell');
        $response->assertSee('data-active-section="patients"', false);
    }

    public function test_patient_page_renders_for_authorized_user(): void
    {
        $this->withoutVite();
        $owner = $this->createUser();
        $patientResponse = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Primary',
            'relationship' => 'self',
        ]);
        $patientId = (int) $patientResponse->json('patient.id');

        $response = $this->actingAs($owner)->get("/phr/patient/{$patientId}");

        $response->assertOk();
        $response->assertViewIs('phr.shell');
        $response->assertSee('PhrShell');
        $response->assertSee("data-patient-id=\"{$patientId}\"", false);
    }

    public function test_patient_page_is_not_accessible_to_unshared_user(): void
    {
        $owner = $this->createUser();
        $otherUser = $this->createUser();
        $patientResponse = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Primary',
            'relationship' => 'self',
        ]);
        $patientId = (int) $patientResponse->json('patient.id');

        $this->actingAs($otherUser)->get("/phr/patient/{$patientId}")->assertNotFound();
    }

    public function test_patient_deep_links_render_the_shell_for_that_patient(): void
    {
        $this->withoutVite();
        $owner = $this->createUser();
        $patientId = $this->createPatient($owner);

        foreach (PageController::PATIENT_PAGES as $page) {
            foreach (["/phr/patient/{$patientId}/{$page}", "/phr/patient/{$patientId}/{$page}/5", "/phr/patient/{$patientId}/{$page}/trend/weight"] as $path) {
                $response = $this->actingAs($owner)->get($path);

                $response->assertOk();
                $response->assertViewIs('phr.shell');
                $response->assertSee("data-patient-id=\"{$patientId}\"", false);
            }
        }
    }

    public function test_patient_deep_links_enforce_patient_access(): void
    {
        $patientId = $this->createPatient($this->createUser());

        $this->actingAs($this->createUser())->get("/phr/patient/{$patientId}/labs/5")->assertNotFound();
    }

    public function test_patient_deep_links_reject_unknown_pages_and_leave_specific_routes_alone(): void
    {
        $this->withoutVite();
        $owner = $this->createUser();
        $patientId = $this->createPatient($owner);

        $this->actingAs($owner)->get("/phr/patient/{$patientId}/not-a-page")->assertNotFound();
        $this->actingAs($owner)->get("/phr/patient/{$patientId}/imaging/series/7/explore-3d")
            ->assertOk()
            ->assertViewIs('phr.explore3d');
    }

    public function test_phr_root_redirects_to_patients_page(): void
    {
        $response = $this->actingAs($this->createUser())->get('/phr');

        $response->assertRedirect('/phr/patients');
    }

    public function test_shell_carries_no_link_out_of_phr(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->createUser())->get('/phr/patients');

        $response->assertOk();
        $response->assertDontSee('data-back-url', false);
        $this->assertNull(config('phr.parent_site_url'));
    }

    public function test_section_routes_render_the_shared_shell(): void
    {
        $this->withoutVite();

        foreach ([
            '/phr/patients/manage' => 'manage-patients',
            '/phr/imports' => 'imports',
            '/phr/config' => 'config',
        ] as $path => $section) {
            $response = $this->actingAs($this->createUser())->get($path);

            $response->assertOk();
            $response->assertViewIs('phr.shell');
            $response->assertSee('PhrShell');
            $response->assertSee("data-active-section=\"{$section}\"", false);
        }
    }

    private function createPatient(User $owner): int
    {
        $response = $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => 'Primary',
            'relationship' => 'self',
        ]);

        return (int) $response->json('patient.id');
    }
}
