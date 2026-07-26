<?php

namespace Tests\Feature\PHR;

use App\Models\PhrPatientUserAccess;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PhrPatientAccessTest extends TestCase
{
    /**
     * Regression: account enumeration through the sharing endpoint.
     *
     * `email` was validated with `exists:users,email`, so any authenticated
     * user could point the grant form at their own throwaway patient, submit
     * an arbitrary address, and read registration status straight off the
     * validation error. The endpoint must answer identically either way.
     */
    public function test_grant_response_is_identical_for_registered_and_unregistered_emails(): void
    {
        $owner = $this->createUser();
        $registered = $this->createUser(['email' => 'registered@example.test']);
        $patientId = $this->createPatientFor($owner);

        $registeredResponse = $this->grant($owner, $patientId, 'registered@example.test');
        $unregisteredResponse = $this->grant($owner, $patientId, 'nobody@example.test');

        $registeredResponse->assertCreated()->assertExactJson(['ok' => true]);
        $unregisteredResponse->assertCreated()->assertExactJson(['ok' => true]);

        $this->assertSame(
            $registeredResponse->getStatusCode(),
            $unregisteredResponse->getStatusCode(),
            'Status must not reveal whether the address belongs to an account.',
        );
        $this->assertSame(
            $registeredResponse->getContent(),
            $unregisteredResponse->getContent(),
            'Body must not reveal whether the address belongs to an account.',
        );

        // The real grant still lands; only the response is uniform.
        $this->assertDatabaseHas('phr_patient_user_access', [
            'patient_id' => $patientId,
            'user_id' => $registered->id,
            'access_level' => 'viewer',
        ]);
        $this->assertSame(
            2,
            PhrPatientUserAccess::query()->where('patient_id', $patientId)->count(),
            'Only the owner row and the registered grantee should exist.',
        );
    }

    public function test_grant_response_does_not_echo_the_target_identity(): void
    {
        $owner = $this->createUser();
        $grantee = $this->createUser(['email' => 'grantee@example.test', 'name' => 'Grantee Name']);
        $patientId = $this->createPatientFor($owner);

        $body = (string) $this->grant($owner, $patientId, 'grantee@example.test')
            ->assertCreated()
            ->getContent();

        $this->assertStringNotContainsString('grantee@example.test', $body);
        $this->assertStringNotContainsString('Grantee Name', $body);
        $this->assertStringNotContainsString((string) $grantee->id, $body);
    }

    public function test_granting_to_the_owners_own_address_is_a_uniform_no_op(): void
    {
        $owner = $this->createUser(['email' => 'owner@example.test']);
        $patientId = $this->createPatientFor($owner);

        $this->grant($owner, $patientId, 'owner@example.test')
            ->assertCreated()
            ->assertExactJson(['ok' => true]);

        // Still just the owner row created at patient-creation time.
        $this->assertSame(
            1,
            PhrPatientUserAccess::query()->where('patient_id', $patientId)->count(),
        );
    }

    public function test_malformed_email_is_still_rejected(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        $this->grant($owner, $patientId, 'not-an-email')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_non_owner_cannot_grant_access(): void
    {
        $owner = $this->createUser();
        $manager = $this->createUser();
        $stranger = $this->createUser();
        $outsider = $this->createUser(['email' => 'outsider@example.test']);
        $patientId = $this->createPatientFor($owner);

        $this->grant($owner, $patientId, $manager->email, 'manager')->assertCreated();

        // A manager holds write access but is not the owner, so cannot re-share.
        $this->grant($manager, $patientId, 'outsider@example.test')->assertForbidden();
        // Someone with no access at all must not learn the patient exists.
        $this->grant($stranger, $patientId, 'outsider@example.test')->assertNotFound();

        $this->assertDatabaseMissing('phr_patient_user_access', [
            'patient_id' => $patientId,
            'user_id' => $outsider->id,
        ]);
    }

    public function test_grant_endpoint_is_rate_limited(): void
    {
        $owner = $this->createUser();
        $patientId = $this->createPatientFor($owner);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->grant($owner, $patientId, "probe{$attempt}@example.test")->assertCreated();
        }

        $this->grant($owner, $patientId, 'probe-too-many@example.test')
            ->assertStatus(429);
    }

    private function grant(User $actor, int $patientId, string $email, string $level = 'viewer'): TestResponse
    {
        return $this->actingAs($actor)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $email,
            'access_level' => $level,
        ]);
    }

    private function createPatientFor(User $owner): int
    {
        return (int) $this->actingAs($owner)
            ->postJson('/api/phr/patients', ['display_name' => 'Primary'])
            ->assertCreated()
            ->json('patient.id');
    }
}
