<?php

namespace Tests\Feature\PHR;

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

    public function test_phr_root_redirects_to_patients_page(): void
    {
        $response = $this->actingAs($this->createUser())->get('/phr');

        $response->assertRedirect('/phr/patients');
    }

    public function test_shell_exposes_the_parent_site_as_the_back_url(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->createUser())->get('/phr/patients');

        $response->assertOk();
        $response->assertSee('data-back-url="https://bherila.net"', false);
    }

    public function test_shell_reads_the_back_url_from_the_identity_provider_config(): void
    {
        $this->withoutVite();
        config(['services.identity_provider.base_url' => 'https://staging.bherila.net/']);

        $response = $this->actingAs($this->createUser())->get('/phr/patients');

        $response->assertOk();
        $response->assertSee('data-back-url="https://staging.bherila.net"', false);
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
}
