<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatient;
use App\Models\PhrRespiratoryEvent;
use App\Models\User;
use Tests\TestCase;

class PhrRespiratoryEventsTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $uuid, array $overrides = []): array
    {
        return array_merge([
            'client_event_uuid' => $uuid,
            'event_type' => 'cough',
            'occurred_at' => '2026-07-01T08:30:00',
            'tz_offset_min' => -420,
            'duration_ms' => 350,
            'confidence' => 0.92,
            'burst_count' => 1,
            'source' => 'desktop-mac',
            'device_id' => '11111111-1111-1111-1111-111111111111',
            'model_version' => 'v1.2',
        ], $overrides);
    }

    public function test_batch_insert_happy_path(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $response = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [
                $this->event('uuid-a', ['event_type' => 'cough']),
                $this->event('uuid-b', ['event_type' => 'sneeze']),
            ],
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'accepted');
        $response->assertJsonPath('results.0.uuid', 'uuid-a');
        $response->assertJsonPath('results.1.status', 'accepted');

        $this->assertSame(2, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_duplicate_client_event_uuid_returns_duplicate_without_double_insert(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('dup-1')],
        ])->assertOk()->assertJsonPath('results.0.status', 'accepted');

        // Re-send the same batch plus one new event and one in-batch duplicate.
        $response = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [
                $this->event('dup-1'),
                $this->event('dup-2'),
                $this->event('dup-2'),
            ],
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'duplicate');
        $response->assertJsonPath('results.1.status', 'accepted');
        $response->assertJsonPath('results.2.status', 'duplicate');

        $this->assertSame(2, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_invalid_event_type_rejected_per_event_but_batch_still_200(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $response = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [
                $this->event('good-1'),
                $this->event('bad-1', ['event_type' => 'not_a_sound']),
                $this->event('bad-2', ['occurred_at' => null]),
            ],
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'accepted');
        $response->assertJsonPath('results.1.status', 'rejected');
        $this->assertNotEmpty($response->json('results.1.reason'));
        $response->assertJsonPath('results.2.status', 'rejected');

        $this->assertSame(1, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_batch_over_500_events_is_rejected_with_422(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $events = [];
        for ($i = 0; $i < 501; $i++) {
            $events[] = $this->event("bulk-{$i}");
        }

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => $events,
        ])->assertUnprocessable()->assertJsonValidationErrors(['events']);
    }

    public function test_cross_patient_write_is_denied(): void
    {
        ['patientId' => $patientId] = $this->createPatientWithAccess();
        $stranger = $this->createUser();

        $this->actingAs($stranger)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('x-1')],
        ])->assertNotFound();

        $this->assertSame(0, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_bearer_token_auth_is_accepted_without_a_session(): void
    {
        // Build the patient without actingAs so the request authenticates purely
        // via the bearer header (a leaked session would mask a broken token path).
        $owner = $this->createUser();
        $plainToken = $owner->issueMcpToken();
        $patientId = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Device Patient',
        ])->id;

        $this->postJson(
            "/api/phr/patients/{$patientId}/respiratory-events/batch",
            ['events' => [$this->event('bearer-1')]],
            ['Authorization' => "Bearer {$plainToken}"],
        )->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $this->assertSame(1, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_bad_bearer_token_is_unauthorized(): void
    {
        $owner = $this->createUser();
        $patientId = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Device Patient',
        ])->id;

        $this->postJson(
            "/api/phr/patients/{$patientId}/respiratory-events/batch",
            ['events' => [$this->event('bearer-bad')]],
            ['Authorization' => 'Bearer not-a-real-token'],
        )->assertUnauthorized();
    }

    public function test_bearer_token_user_cannot_write_to_another_users_patient(): void
    {
        // Cross-patient authorization must hold for bearer-authed device requests too.
        $plainToken = $this->createUser()->issueMcpToken();

        $otherOwner = $this->createUser();
        $patientId = PhrPatient::query()->create([
            'owner_user_id' => $otherOwner->id,
            'display_name' => 'Other Patient',
        ])->id;

        $this->postJson(
            "/api/phr/patients/{$patientId}/respiratory-events/batch",
            ['events' => [$this->event('cross-1')]],
            ['Authorization' => "Bearer {$plainToken}"],
        )->assertNotFound();

        $this->assertSame(0, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_form_encoded_write_requests_are_refused(): void
    {
        // The batch paths are CSRF-exempt, so a cross-site hidden form could
        // otherwise ride a victim's session cookie. Form-encoded bodies must
        // be refused (415); only JSON (CORS-preflighted cross-origin) is accepted.
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->post("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('form-1')],
        ])->assertStatus(415);

        $this->assertSame(0, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('form-2')],
        ])->assertOk();

        $this->actingAs($owner)->delete("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'uuids' => ['form-2'],
        ])->assertStatus(415);

        $this->assertSame(1, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_delete_batch_is_idempotent(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('del-1'), $this->event('del-2')],
        ])->assertOk();

        $first = $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'uuids' => ['del-1', 'del-missing'],
        ])->assertOk();

        $first->assertJsonPath('deleted', 1);
        $first->assertJsonPath('results.0.status', 'deleted');
        $first->assertJsonPath('results.1.status', 'not_found');

        // Second delete of the same uuid is a no-op but still succeeds.
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'uuids' => ['del-1'],
        ])->assertOk()->assertJsonPath('results.0.status', 'not_found');

        $this->assertSame(1, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_index_filters_by_from_to_and_type(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [
                $this->event('e1', ['event_type' => 'cough', 'occurred_at' => '2026-07-01T08:00:00']),
                $this->event('e2', ['event_type' => 'sneeze', 'occurred_at' => '2026-07-05T08:00:00']),
                $this->event('e3', ['event_type' => 'cough', 'occurred_at' => '2026-07-10T08:00:00']),
            ],
        ])->assertOk();

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events")
            ->assertOk()
            ->assertJsonCount(3, 'respiratory_events')
            ->assertJsonPath('can_manage', true);

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events?type=cough")
            ->assertOk()
            ->assertJsonCount(2, 'respiratory_events');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events?from=2026-07-04&to=2026-07-06")
            ->assertOk()
            ->assertJsonCount(1, 'respiratory_events')
            ->assertJsonPath('respiratory_events.0.client_event_uuid', 'e2');
    }

    public function test_summary_buckets_by_local_day(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [
                // 2026-07-02 02:00 UTC, -420 min => 2026-07-01 local day.
                $this->event('s1', ['event_type' => 'cough', 'occurred_at' => '2026-07-02T02:00:00', 'tz_offset_min' => -420, 'burst_count' => 2]),
                $this->event('s2', ['event_type' => 'sneeze', 'occurred_at' => '2026-07-02T03:00:00', 'tz_offset_min' => -420, 'burst_count' => 1]),
                // 2026-07-03 08:00 local.
                $this->event('s3', ['event_type' => 'cough', 'occurred_at' => '2026-07-03T15:00:00', 'tz_offset_min' => -420, 'burst_count' => 1]),
            ],
        ])->assertOk();

        $response = $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events/summary")
            ->assertOk()
            ->assertJsonPath('bucket', 'day')
            ->assertJsonCount(2, 'buckets');

        $response->assertJsonPath('buckets.0.date', '2026-07-01');
        $response->assertJsonPath('buckets.0.count', 2);
        $response->assertJsonPath('buckets.0.burst_total', 3);
        $response->assertJsonPath('buckets.0.by_type.cough', 1);
        $response->assertJsonPath('buckets.0.by_type.sneeze', 1);
        $response->assertJsonPath('buckets.1.date', '2026-07-03');
        $response->assertJsonPath('buckets.1.count', 1);
    }

    public function test_viewer_cannot_write_but_can_read(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('v1')],
        ])->assertOk();

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/respiratory-events")
            ->assertOk()
            ->assertJsonCount(1, 'respiratory_events')
            ->assertJsonPath('can_manage', false);

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('v2')],
        ])->assertForbidden();
    }

    public function test_intensity_fields_round_trip(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('loud-1', [
                'peak_dbfs' => -6.5,
                'mean_dbfs' => -18.25,
                'noise_floor_dbfs' => -52.0,
            ])],
        ])->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events")
            ->assertOk()
            ->assertJsonPath('respiratory_events.0.peak_dbfs', -6.5)
            ->assertJsonPath('respiratory_events.0.mean_dbfs', -18.25)
            // A whole-number float serialises as an int in JSON.
            ->assertJsonPath('respiratory_events.0.noise_floor_dbfs', -52);
    }

    public function test_intensity_is_optional_and_out_of_range_is_rejected_per_event(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $response = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [
                // Older devices omit loudness entirely.
                $this->event('no-db'),
                $this->event('too-loud', ['peak_dbfs' => 400]),
                $this->event('too-quiet', ['mean_dbfs' => -9999]),
            ],
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'accepted');
        $response->assertJsonPath('results.1.status', 'rejected');
        $response->assertJsonPath('results.2.status', 'rejected');

        $this->assertSame(1, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_false_positive_is_hidden_from_reads_but_retained(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('fp-1'), $this->event('keep-1')],
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'fp-1', 'false_positive' => true, 'corrected_to' => null]],
        ])->assertOk()
            ->assertJsonPath('flagged', 1)
            ->assertJsonPath('results.0.status', 'flagged');

        // Hidden from normal reads...
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events")
            ->assertOk()
            ->assertJsonCount(1, 'respiratory_events')
            ->assertJsonPath('respiratory_events.0.client_event_uuid', 'keep-1');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events/summary")
            ->assertOk()
            ->assertJsonPath('buckets.0.count', 1);

        // ...but retained for auditing, and never deleted.
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events?include_false_positives=1")
            ->assertOk()
            ->assertJsonCount(2, 'respiratory_events');

        $this->assertSame(2, PhrRespiratoryEvent::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_correction_keeps_counting_under_the_corrected_class(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('mis-1', ['event_type' => 'sneeze'])],
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'mis-1', 'false_positive' => false, 'corrected_to' => 'nose_blow']],
        ])->assertOk()->assertJsonPath('results.0.status', 'flagged');

        // A correction relabels a real event; it does not erase it.
        $summary = $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events/summary")
            ->assertOk()
            ->assertJsonPath('buckets.0.count', 1);

        $summary->assertJsonPath('buckets.0.by_type.nose_blow', 1);
        $summary->assertJsonMissingPath('buckets.0.by_type.sneeze');

        // Type filtering follows the effective label in both directions.
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events?type=nose_blow")
            ->assertOk()
            ->assertJsonCount(1, 'respiratory_events')
            ->assertJsonPath('respiratory_events.0.effective_event_type', 'nose_blow');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events?type=sneeze")
            ->assertOk()
            ->assertJsonCount(0, 'respiratory_events');
    }

    public function test_flag_can_be_cleared_so_undo_syncs(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('undo-1')],
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'undo-1', 'false_positive' => true, 'corrected_to' => null]],
        ])->assertOk();

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events")
            ->assertOk()->assertJsonCount(0, 'respiratory_events');

        // The device's Undo is just a declarative re-flag.
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'undo-1', 'false_positive' => false, 'corrected_to' => null]],
        ])->assertOk()->assertJsonPath('results.0.status', 'flagged');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/respiratory-events")
            ->assertOk()
            ->assertJsonCount(1, 'respiratory_events')
            ->assertJsonPath('respiratory_events.0.false_positive_at', null);
    }

    public function test_flagging_an_unknown_uuid_reports_not_found_without_erroring(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        // The device treats not_found as terminal, so a flag on an event the
        // server never accepted cannot retry forever.
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'never-uploaded', 'false_positive' => true, 'corrected_to' => null]],
        ])->assertOk()
            ->assertJsonPath('flagged', 0)
            ->assertJsonPath('results.0.status', 'not_found');
    }

    public function test_flag_batch_rejects_unknown_corrected_class_and_form_encoding(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'x', 'false_positive' => false, 'corrected_to' => 'not_a_sound']],
        ])->assertUnprocessable();

        // CSRF-exempt path must refuse form-encoded bodies.
        $this->actingAs($owner)->post("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'x', 'false_positive' => true, 'corrected_to' => null]],
        ])->assertStatus(415);
    }

    public function test_viewer_cannot_flag_events(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/respiratory-events/batch", [
            'events' => [$this->event('vf-1')],
        ])->assertOk();

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/respiratory-events/flag-batch", [
            'items' => [['uuid' => 'vf-1', 'false_positive' => true, 'corrected_to' => null]],
        ])->assertForbidden();
    }
}
