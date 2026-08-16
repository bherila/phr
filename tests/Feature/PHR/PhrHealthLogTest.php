<?php

namespace Tests\Feature\PHR;

use App\Models\User;
use Tests\TestCase;

class PhrHealthLogTest extends TestCase
{
    public function test_owner_can_create_list_and_view_health_logs_and_entries(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatient($owner);

        $logId = (int) $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/health-logs", [
            'name' => 'Daily symptom journal',
            'kind' => 'symptom',
            'description' => 'A synthetic symptom log.',
        ])->assertCreated()
            ->assertJsonPath('health_log.name', 'Daily symptom journal')
            ->assertJsonPath('health_log.entries_count', 0)
            ->assertJsonPath('health_log.latest_entry_at', null)
            ->json('health_log.id');

        $entryId = (int) $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries", [
            'occurred_at' => '2026-07-13 08:30:00',
            'title' => 'Morning check-in',
            'notes' => 'Synthetic observations only.',
            'intensity' => 4,
            'tags' => ['morning', 'synthetic'],
            'details' => ['duration_minutes' => 30],
        ])->assertCreated()
            ->assertJsonPath('entry.intensity', 4)
            ->assertJsonPath('entry.tags.0', 'morning')
            ->assertJsonPath('entry.details.duration_minutes', 30)
            ->json('entry.id');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/health-logs")
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('health_logs.0.entries_count', 1)
            ->assertJsonPath('health_logs.0.latest_entry_at', '2026-07-13 08:30:00');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries/{$entryId}")
            ->assertOk()
            ->assertJsonPath('entry.title', 'Morning check-in');

        $this->assertDatabaseHas('phr_health_logs', [
            'id' => $logId,
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('phr_health_log_entries', [
            'id' => $entryId,
            'patient_id' => $patientId,
            'user_id' => $owner->id,
            'recorded_by_user_id' => $owner->id,
        ]);
    }

    public function test_manager_can_mutate_while_viewer_has_read_only_access(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $viewer = $this->createUser();
        $patientId = $this->createPatient($owner);
        $this->grantPatientAccess($owner, $patientId, $manager, 'manager');
        $this->grantPatientAccess($owner, $patientId, $viewer, 'viewer');

        $logId = (int) $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/health-logs", [
            'name' => 'Meal journal',
            'kind' => 'meal',
        ])->assertCreated()
            ->assertJsonPath('health_log.user_id', $owner->id)
            ->assertJsonPath('health_log.created_by_user_id', $manager->id)
            ->json('health_log.id');

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries", [
            'occurred_at' => '2026-07-13 12:00:00',
            'title' => 'Synthetic lunch',
        ])->assertCreated()
            ->assertJsonPath('entry.user_id', $owner->id)
            ->assertJsonPath('entry.recorded_by_user_id', $manager->id);

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/health-logs")
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonCount(1, 'health_logs');

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries")
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonCount(1, 'entries');

        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patientId}/health-logs/{$logId}", [
            'name' => 'Not permitted',
        ])->assertForbidden();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries", [
            'occurred_at' => '2026-07-13 13:00:00',
        ])->assertForbidden();
    }

    public function test_owner_can_update_and_delete_logs_and_entries(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatient($owner);
        $logId = $this->createHealthLog($owner, $patientId, 'Snack journal', 'snack');
        $entryId = $this->createHealthLogEntry($owner, $patientId, $logId);

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/health-logs/{$logId}", [
            'name' => 'Updated snack journal',
            'description' => 'Updated synthetic description.',
            'archived_at' => '2026-07-13 15:00:00',
        ])->assertOk()
            ->assertJsonPath('health_log.name', 'Updated snack journal')
            ->assertJsonPath('health_log.archived_at', '2026-07-13 15:00:00');

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries/{$entryId}", [
            'title' => 'Updated entry',
            'intensity' => 8,
        ])->assertOk()
            ->assertJsonPath('entry.title', 'Updated entry')
            ->assertJsonPath('entry.intensity', 8);

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries/{$entryId}")
            ->assertNoContent();
        $this->assertDatabaseMissing('phr_health_log_entries', ['id' => $entryId]);

        $replacementEntryId = $this->createHealthLogEntry($owner, $patientId, $logId);
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/health-logs/{$logId}")
            ->assertNoContent();
        $this->assertDatabaseMissing('phr_health_logs', ['id' => $logId]);
        $this->assertDatabaseMissing('phr_health_log_entries', ['id' => $replacementEntryId]);
    }

    public function test_nested_ids_are_scoped_to_the_patient_and_health_log(): void
    {
        $owner = $this->createUser();
        $firstPatientId = $this->createPatient($owner, 'Synthetic Patient A');
        $secondPatientId = $this->createPatient($owner, 'Synthetic Patient B');
        $firstLogId = $this->createHealthLog($owner, $firstPatientId, 'First log', 'custom');
        $secondLogId = $this->createHealthLog($owner, $firstPatientId, 'Second log', 'custom');
        $entryId = $this->createHealthLogEntry($owner, $firstPatientId, $firstLogId);

        $this->actingAs($owner)->getJson("/api/phr/patients/{$secondPatientId}/health-logs/{$firstLogId}")
            ->assertNotFound();
        $this->actingAs($owner)->getJson("/api/phr/patients/{$firstPatientId}/health-logs/{$secondLogId}/entries/{$entryId}")
            ->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$firstPatientId}/health-logs/{$secondLogId}/entries/{$entryId}")
            ->assertNotFound();

        $this->assertDatabaseHas('phr_health_log_entries', ['id' => $entryId]);
    }

    public function test_unshared_user_cannot_discover_health_logs(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $patientId = $this->createPatient($owner);
        $logId = $this->createHealthLog($owner, $patientId, 'Private synthetic log', 'custom');

        $this->actingAs($other)->getJson("/api/phr/patients/{$patientId}/health-logs")->assertNotFound();
        $this->actingAs($other)->getJson("/api/phr/patients/{$patientId}/health-logs/{$logId}")->assertNotFound();
    }

    public function test_health_log_and_entry_validation_rejects_invalid_payloads(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatient($owner);

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/health-logs", [
            'name' => '',
            'kind' => 'unsupported',
            'description' => str_repeat('x', 1001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'kind', 'description']);

        $logId = $this->createHealthLog($owner, $patientId, 'Unique synthetic log', 'custom');
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/health-logs", [
            'name' => 'Unique synthetic log',
            'kind' => 'custom',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries", [
            'intensity' => 11,
            'tags' => ['duplicate', 'duplicate'],
            'details' => 'not-an-object',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['occurred_at', 'intensity', 'tags.1', 'details']);

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries", [
            'occurred_at' => '2026-07-13 16:00:00',
            'details' => ['synthetic-list-value'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('details');
    }

    public function test_health_log_api_requires_authentication(): void
    {
        $this->getJson('/api/phr/patients/1/health-logs')->assertUnauthorized();
        $this->postJson('/api/phr/patients/1/health-logs', [
            'name' => 'Synthetic log',
            'kind' => 'custom',
        ])->assertUnauthorized();
    }

    private function createPatient(User $owner, string $displayName = 'Synthetic Patient'): int
    {
        return (int) $this->actingAs($owner)->postJson('/api/phr/patients', [
            'display_name' => $displayName,
            'relationship' => 'self',
        ])->assertCreated()->json('patient.id');
    }

    private function grantPatientAccess(User $owner, int $patientId, User $user, string $accessLevel): void
    {
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $user->email,
            'access_level' => $accessLevel,
        ])->assertCreated();
    }

    private function createHealthLog(User $actor, int $patientId, string $name, string $kind): int
    {
        return (int) $this->actingAs($actor)->postJson("/api/phr/patients/{$patientId}/health-logs", [
            'name' => $name,
            'kind' => $kind,
        ])->assertCreated()->json('health_log.id');
    }

    private function createHealthLogEntry(User $actor, int $patientId, int $logId): int
    {
        return (int) $this->actingAs($actor)->postJson("/api/phr/patients/{$patientId}/health-logs/{$logId}/entries", [
            'occurred_at' => '2026-07-13 14:00:00',
            'title' => 'Synthetic entry',
        ])->assertCreated()->json('entry.id');
    }
}
