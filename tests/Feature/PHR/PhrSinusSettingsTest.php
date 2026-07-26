<?php

namespace Tests\Feature\PHR;

use App\Models\PhrSinusSetting;
use App\Models\User;
use Tests\TestCase;

class PhrSinusSettingsTest extends TestCase
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

    public function test_show_returns_null_before_anything_is_synced(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/sinus-settings")
            ->assertOk()
            ->assertJsonPath('sinus_settings', null)
            ->assertJsonPath('can_manage', true);
    }

    public function test_values_are_stored_as_strings(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        // The device deserialises this document as a string map, so a stored
        // JSON number would make every one of its flushes fail to parse.
        // Validation accepts `numeric`/`integer`, so normalisation happens on
        // the way in rather than being pushed onto clients.
        $response = $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.65, 'quiet_start' => 22, 'quiet_end' => null],
            'updated_at' => '2026-07-08T10:00:00Z',
        ])->assertOk();

        $settings = $response->json('sinus_settings.settings');

        $this->assertSame('0.65', $settings['sensitivity']);
        $this->assertSame('22', $settings['quiet_start']);
        // Nulls are dropped rather than stringified.
        $this->assertArrayNotHasKey('quiet_end', $settings);
    }

    public function test_first_put_creates_the_document(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.7, 'quiet_start' => 22, 'quiet_end' => 7],
            'updated_at' => '2026-07-01T10:00:00Z',
            'device_id' => 'device-a',
        ])->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('sinus_settings.settings.sensitivity', '0.7')
            ->assertJsonPath('sinus_settings.updated_by_device', 'device-a');

        $this->assertSame(1, PhrSinusSetting::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_newer_timestamp_wins_and_older_is_rejected_with_server_state(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.5],
            'updated_at' => '2026-07-02T10:00:00Z',
        ])->assertOk()->assertJsonPath('applied', true);

        // A newer write wins.
        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.9],
            'updated_at' => '2026-07-02T11:00:00Z',
        ])->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('sinus_settings.settings.sensitivity', '0.9');

        // A stale device loses, and is handed the winning document in the same
        // round trip so it can adopt server state without a second request.
        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.1],
            'updated_at' => '2026-07-02T09:00:00Z',
        ])->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('sinus_settings.settings.sensitivity', '0.9');
    }

    public function test_device_local_keys_are_never_stored(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        // Sync mode, server URL and friends are per-machine concerns; pulling
        // `offline-strict` onto a second machine would silently disable its sync.
        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => [
                'sensitivity' => 0.4,
                'mode' => 'offline-strict',
                'server_url' => 'https://example.test',
                'patient_id' => '99',
                'device_id' => 'should-not-sync',
            ],
            'updated_at' => '2026-07-03T10:00:00Z',
        ])->assertOk()
            ->assertJsonPath('sinus_settings.settings.sensitivity', '0.4')
            ->assertJsonMissingPath('sinus_settings.settings.mode')
            ->assertJsonMissingPath('sinus_settings.settings.server_url')
            ->assertJsonMissingPath('sinus_settings.settings.patient_id')
            ->assertJsonMissingPath('sinus_settings.settings.device_id');
    }

    public function test_far_future_timestamp_is_rejected(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        // A device with a fast clock would otherwise win every race forever.
        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.5],
            'updated_at' => now()->addDay()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['updated_at']);
    }

    public function test_out_of_range_sensitivity_is_rejected(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 5],
            'updated_at' => '2026-07-04T10:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors(['settings.sensitivity']);
    }

    public function test_form_encoded_writes_are_refused(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->put("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.5],
            'updated_at' => '2026-07-04T10:00:00Z',
        ])->assertStatus(415);
    }

    public function test_bearer_token_auth_is_accepted_without_a_session(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $plain = 'sinus-settings-token';
        $owner->forceFill(['mcp_api_key' => hash('sha256', $plain)])->save();

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
                'settings' => ['sensitivity' => 0.6],
                'updated_at' => '2026-07-05T10:00:00Z',
            ])->assertOk()->assertJsonPath('applied', true);
    }

    public function test_cross_patient_access_is_denied(): void
    {
        ['patientId' => $patientId] = $this->createPatientWithAccess();
        $stranger = $this->createUser();

        $this->actingAs($stranger)->getJson("/api/phr/patients/{$patientId}/sinus-settings")
            ->assertNotFound();

        $this->actingAs($stranger)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.5],
            'updated_at' => '2026-07-06T10:00:00Z',
        ])->assertNotFound();
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.8],
            'updated_at' => '2026-07-07T10:00:00Z',
        ])->assertOk();

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/sinus-settings")
            ->assertOk()
            ->assertJsonPath('sinus_settings.settings.sensitivity', '0.8')
            ->assertJsonPath('can_manage', false);

        $this->actingAs($viewer)->putJson("/api/phr/patients/{$patientId}/sinus-settings", [
            'settings' => ['sensitivity' => 0.1],
            'updated_at' => '2026-07-07T11:00:00Z',
        ])->assertForbidden();
    }
}
