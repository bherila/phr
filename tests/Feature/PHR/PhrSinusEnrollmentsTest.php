<?php

namespace Tests\Feature\PHR;

use App\Models\PhrSinusEnrollment;
use App\Models\User;
use Tests\TestCase;

class PhrSinusEnrollmentsTest extends TestCase
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
     * Little-endian f32 bytes, exactly as the device's SQLite BLOB stores them.
     *
     * @param  list<float>  $values
     */
    private function embedding(array $values): string
    {
        $packed = '';

        foreach ($values as $value) {
            $packed .= pack('g', $value);
        }

        return $packed;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function enrollment(string $uuidSeed, array $overrides = []): array
    {
        $values = [0.1, 0.2, 0.3, 0.4];

        return array_merge([
            'client_enrollment_uuid' => base64_encode(str_pad($uuidSeed, 16, "\0")),
            'class' => 'hawk',
            'is_negative' => false,
            'embedding' => base64_encode($this->embedding($values)),
            'embedding_dim' => count($values),
            'model_version' => 'yamnet+proto@0',
            'similarity' => 0.88,
            'separation' => 0.12,
            'peak_dbfs' => -14.5,
            'source_event_uuid' => null,
            'device_id' => 'device-a',
            'captured_at' => '2026-07-01T08:30:00',
        ], $overrides);
    }

    public function test_batch_insert_happy_path(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [
                $this->enrollment('a', ['class' => 'hawk']),
                $this->enrollment('b', ['class' => 'sniffle', 'is_negative' => true]),
            ],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'accepted')
            ->assertJsonPath('results.1.status', 'accepted');

        $this->assertSame(2, PhrSinusEnrollment::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_embedding_bytes_round_trip_unchanged(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        // Values chosen to be exactly representable in f32 so the assertion is
        // about byte fidelity, not float formatting.
        $values = [0.5, -0.25, 0.125, -1.0];
        $raw = $this->embedding($values);
        $uuid = str_pad('round-trip', 16, "\0");

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('unused', [
                'client_enrollment_uuid' => base64_encode($uuid),
                'embedding' => base64_encode($raw),
                'embedding_dim' => count($values),
            ])],
        ])->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $response = $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/sinus-enrollments")
            ->assertOk()
            ->assertJsonCount(1, 'sinus_enrollments');

        $this->assertSame(base64_encode($raw), $response->json('sinus_enrollments.0.embedding'));
        $this->assertSame(base64_encode($uuid), $response->json('sinus_enrollments.0.client_enrollment_uuid'));
        $this->assertSame($values, array_values(unpack('g*', (string) base64_decode(
            (string) $response->json('sinus_enrollments.0.embedding'), true
        ))));
    }

    public function test_duplicate_uuid_returns_duplicate_without_double_insert(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('dup')],
        ])->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [
                $this->enrollment('dup'),
                $this->enrollment('new'),
                $this->enrollment('new'),
            ],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.1.status', 'accepted')
            ->assertJsonPath('results.2.status', 'duplicate');

        $this->assertSame(2, PhrSinusEnrollment::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_malformed_items_are_rejected_per_item_but_batch_still_200(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $response = $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [
                $this->enrollment('ok'),
                // Declared dimension disagrees with the byte length: better a
                // rejection than a silently corrupted vector.
                $this->enrollment('dim', ['embedding_dim' => 99]),
                $this->enrollment('b64', ['embedding' => 'not base64!!']),
                $this->enrollment('uid', ['client_enrollment_uuid' => base64_encode('short')]),
                $this->enrollment('cls', ['class' => 'not_a_sound']),
            ],
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'accepted');
        $response->assertJsonPath('results.1.status', 'rejected');
        $response->assertJsonPath('results.2.status', 'rejected');
        $response->assertJsonPath('results.3.status', 'rejected');
        $response->assertJsonPath('results.4.status', 'rejected');
        $this->assertNotEmpty($response->json('results.1.reason'));

        $this->assertSame(1, PhrSinusEnrollment::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_oversized_embedding_is_rejected(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        // One dim past the VARBINARY(16384) column width.
        $dim = intdiv(PhrSinusEnrollment::MAX_EMBEDDING_BYTES, PhrSinusEnrollment::BYTES_PER_DIM) + 1;

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('big', [
                'embedding' => base64_encode(str_repeat("\0", $dim * PhrSinusEnrollment::BYTES_PER_DIM)),
                'embedding_dim' => $dim,
            ])],
        ])->assertOk()->assertJsonPath('results.0.status', 'rejected');

        $this->assertSame(0, PhrSinusEnrollment::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_batch_over_the_limit_is_rejected_with_422(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $enrollments = [];
        for ($i = 0; $i <= 100; $i++) {
            $enrollments[] = $this->enrollment("bulk-{$i}");
        }

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => $enrollments,
        ])->assertUnprocessable()->assertJsonValidationErrors(['enrollments']);
    }

    public function test_delete_batch_is_idempotent(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('del')],
        ])->assertOk();

        $encoded = base64_encode(str_pad('del', 16, "\0"));

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'uuids' => [$encoded],
        ])->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('results.0.status', 'deleted');

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'uuids' => [$encoded],
        ])->assertOk()
            ->assertJsonPath('deleted', 0)
            ->assertJsonPath('results.0.status', 'not_found');

        $this->assertSame(0, PhrSinusEnrollment::query()->where('phr_patient_id', $patientId)->count());
    }

    public function test_negative_can_link_back_to_the_event_that_caused_it(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('neg', [
                'is_negative' => true,
                'source_event_uuid' => 'event-uuid-123',
            ])],
        ])->assertOk()->assertJsonPath('results.0.status', 'accepted');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/sinus-enrollments")
            ->assertOk()
            ->assertJsonPath('sinus_enrollments.0.is_negative', true)
            ->assertJsonPath('sinus_enrollments.0.source_event_uuid', 'event-uuid-123');
    }

    public function test_form_encoded_writes_are_refused(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->post("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('form')],
        ])->assertStatus(415);

        $this->actingAs($owner)->delete("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'uuids' => [base64_encode(str_pad('form', 16, "\0"))],
        ])->assertStatus(415);
    }

    public function test_cross_patient_write_is_denied(): void
    {
        ['patientId' => $patientId] = $this->createPatientWithAccess();
        $stranger = $this->createUser();

        $this->actingAs($stranger)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('x')],
        ])->assertNotFound();
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->createPatientWithAccess();

        $this->actingAs($owner)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('v1')],
        ])->assertOk();

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/sinus-enrollments")
            ->assertOk()
            ->assertJsonCount(1, 'sinus_enrollments')
            ->assertJsonPath('can_manage', false);

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/sinus-enrollments/batch", [
            'enrollments' => [$this->enrollment('v2')],
        ])->assertForbidden();
    }
}
