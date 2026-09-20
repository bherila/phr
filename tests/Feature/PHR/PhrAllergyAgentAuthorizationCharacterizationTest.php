<?php

namespace Tests\Feature\PHR;

use App\Models\PhrAllergy;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * Characterization of the token-authenticated authorization boundary for the
 * patient + allergy pilot.
 *
 * The invariant these tests pin is that a token ability narrows the actor's own
 * permissions and never widens them: holding `clinical:write` does not make a
 * read-only grant writable, and holding a read scope does not reach a patient
 * the actor was never granted. The write surface is also characterized for what
 * it must not disclose, and for the review lifecycle it may not assert.
 *
 * All fixtures are synthetic.
 */
final class PhrAllergyAgentAuthorizationCharacterizationTest extends TestCase
{
    use ConfiguresPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
    }

    // ── Token abilities restrict, they do not expand ──────────────────────────

    public function test_a_write_scope_does_not_upgrade_a_read_only_grant(): void
    {
        $owner = $this->user('agent-owner@example.test');
        $viewer = $this->user('agent-viewer@example.test');
        $patient = $this->patient($owner, 'Synthetic Agent Patient');
        $this->grant($patient, $viewer, PhrPatientUserAccess::LEVEL_VIEWER, $owner);
        $client = $this->client('Synthetic Agent Client');

        // The reader may read through the agent API.
        Passport::actingAs($viewer, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/allergies")
            ->assertOk()
            ->assertJsonPath('resource_type', 'allergies');

        // The same reader holding a write scope is still only a reader.
        Passport::actingAs($viewer, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertForbidden();

        Passport::actingAs($viewer, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertForbidden();

        // And the owner's write is refused when the token lacks the scope.
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('phr_allergies', 0);
    }

    public function test_no_grant_is_answered_as_not_found_whatever_the_token_carries(): void
    {
        $owner = $this->user('agent-grant-owner@example.test');
        $stranger = $this->user('agent-stranger@example.test');
        $patient = $this->patient($owner, 'Synthetic Guarded Patient');
        $allergy = $this->seedAllergy($patient, $owner);
        $client = $this->client('Synthetic Stranger Client');

        Passport::actingAs($stranger, AgentApiScopes::ids(), 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/allergies")->assertNotFound();
        $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$allergy->id}")->assertNotFound();
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertNotFound();
        $this->postJson("/api/v1/patients/{$patient->id}/allergies/resolve", [
            'external_ids' => ['synthetic-allergy-001'],
        ])->assertNotFound();

        // Every token ability in the catalogue, and still nothing was disclosed.
        $denied = (string) $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$allergy->id}")
            ->getContent();
        $this->assertStringNotContainsString('Synthetic seeded substance', $denied);
    }

    public function test_access_ends_with_the_grant_row(): void
    {
        $owner = $this->user('revoke-owner@example.test');
        $manager = $this->user('revoke-manager@example.test');
        $patient = $this->patient($owner, 'Synthetic Revocation Patient');
        $grant = $this->grant($patient, $manager, PhrPatientUserAccess::LEVEL_MANAGER, $owner);
        $client = $this->client('Synthetic Revocation Client');

        Passport::actingAs($manager, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertCreated();

        $grant->delete();

        // The next request re-resolves access; a token issued earlier carries
        // no cached permission with it.
        $this->getJson("/api/v1/patients/{$patient->id}/allergies")->assertNotFound();
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertNotFound();
    }

    // ── Write-only token responses ────────────────────────────────────────────

    public function test_a_write_only_token_receives_a_receipt_and_never_allergy_contents(): void
    {
        $owner = $this->user('receipt-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Receipt Patient');
        $client = $this->client('Synthetic Receipt Client');

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $created = $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertCreated()
            ->assertJsonPath('receipt_only', true)
            ->json();

        $this->assertSame(['id', 'patient_id', 'review_status'], array_keys($created['data']));
        $this->assertSame(PhrReviewStatus::PENDING, $created['data']['review_status']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $created['version']);

        // Someone adds a private detail in the browser.
        $allergy = PhrAllergy::query()->findOrFail($created['data']['id']);
        $allergy->update(['notes' => 'SENTINEL-BROWSER-ONLY']);

        // An idempotent replay must not hand it back, and neither must a retraction.
        $replay = $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertOk()
            ->assertJsonPath('receipt_only', true);
        $this->assertStringNotContainsString('SENTINEL-BROWSER-ONLY', $replay->getContent() ?: '');
        $this->assertSame(['id', 'patient_id', 'review_status'], array_keys($replay->json('data')));

        $retracted = $this->postJson(
            "/api/v1/patients/{$patient->id}/allergies/{$allergy->id}/retract",
            ['expected_version' => $this->versionOf($allergy->fresh())],
        )->assertOk()->assertJsonPath('receipt_only', true);
        $this->assertStringNotContainsString('SENTINEL-BROWSER-ONLY', $retracted->getContent() ?: '');
        $this->assertSame('retracted', $retracted->json('lifecycle'));

        // The same caller holding the read scope does receive the full resource.
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$allergy->id}")
            ->assertNotFound('A retracted record must leave the read surface.');
    }

    // ── Clinical review restrictions ──────────────────────────────────────────

    public function test_an_agent_cannot_mark_its_own_allergy_input_accepted(): void
    {
        $owner = $this->user('review-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Review Patient');
        $client = $this->client('Synthetic Review Client');

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        // Asserting the status on create is refused rather than ignored, so a
        // caller can never believe it confirmed a record.
        $payload = $this->allergyPayload();
        $payload['review_status'] = PhrReviewStatus::CONFIRMED;
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_status');

        // Nor inside the clinical payload, which is a closed allow-list.
        $nested = $this->allergyPayload();
        $nested['data']['review_status'] = PhrReviewStatus::CONFIRMED;
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $nested)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('data');

        $created = $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertCreated()
            ->json();
        $this->assertSame(PhrReviewStatus::PENDING, $created['data']['review_status']);

        $allergyId = (int) $created['data']['id'];
        $this->patchJson("/api/v1/patients/{$patient->id}/allergies/{$allergyId}", [
            'expected_version' => $created['version'],
            'review_status' => PhrReviewStatus::CONFIRMED,
            'data' => ['severity' => 'severe'],
        ])->assertUnprocessable()->assertJsonValidationErrors('review_status');

        $this->assertSame(
            PhrReviewStatus::PENDING,
            PhrAllergy::query()->findOrFail($allergyId)->review_status,
        );
    }

    public function test_an_effective_agent_edit_reopens_review_and_an_idempotent_replay_does_not(): void
    {
        $owner = $this->user('reopen-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Reopen Patient');
        $client = $this->client('Synthetic Reopen Client');

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $created = $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertCreated()
            ->json();
        $allergyId = (int) $created['data']['id'];

        // A human confirms it in the browser.
        $this->actingAs($owner)
            ->patchJson("/api/phr/patients/{$patient->id}/allergies/{$allergyId}/review", [
                'review_status' => PhrReviewStatus::CONFIRMED,
            ])->assertOk();
        $this->app->get('auth')->forgetGuards();
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        // Resending the identical payload is a no-op and leaves the decision alone.
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertOk()
            ->assertJsonPath('outcome', 'unchanged');
        $this->assertSame(
            PhrReviewStatus::CONFIRMED,
            PhrAllergy::query()->findOrFail($allergyId)->review_status,
        );

        // A real change reopens review, and needs the current version to land.
        $changed = $this->allergyPayload();
        $changed['data']['severity'] = 'severe';
        $changed['expected_version'] = 'f'.str_repeat('0', 63);
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $changed)
            ->assertStatus(409);

        $changed['expected_version'] = $this->versionOf(PhrAllergy::query()->find($allergyId));
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $changed)
            ->assertOk()
            ->assertJsonPath('outcome', 'updated');
        $this->assertSame(
            PhrReviewStatus::PENDING,
            PhrAllergy::query()->findOrFail($allergyId)->review_status,
        );
    }

    // ── Wrong patient/child pair, and two accessible patients ─────────────────

    public function test_an_allergy_is_unreachable_under_the_wrong_patient_even_for_an_actor_holding_both(): void
    {
        $owner = $this->user('pair-owner@example.test');
        $secondOwner = $this->user('pair-second-owner@example.test');
        $patientA = $this->patient($owner, 'Synthetic Patient A');
        $patientB = $this->patient($secondOwner, 'Synthetic Patient B');
        $this->grant($patientB, $owner, PhrPatientUserAccess::LEVEL_MANAGER, $secondOwner);
        $client = $this->client('Synthetic Pair Client');

        $allergyB = $this->seedAllergy($patientB, $secondOwner, 'Synthetic substance in B');

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        // Both patients really are reachable.
        $this->getJson("/api/v1/patients/{$patientA->id}/allergies")->assertOk();
        $this->getJson("/api/v1/patients/{$patientB->id}/allergies/{$allergyB->id}")->assertOk();

        // The mismatched pair is not, on read, patch or retract.
        $this->getJson("/api/v1/patients/{$patientA->id}/allergies/{$allergyB->id}")
            ->assertNotFound();
        $this->patchJson("/api/v1/patients/{$patientA->id}/allergies/{$allergyB->id}", [
            'expected_version' => $this->versionOf($allergyB),
            'data' => ['severity' => 'severe'],
        ])->assertNotFound();
        $this->postJson("/api/v1/patients/{$patientA->id}/allergies/{$allergyB->id}/retract", [
            'expected_version' => $this->versionOf($allergyB),
        ])->assertNotFound();

        $this->assertNull(PhrAllergy::query()->findOrFail($allergyB->id)->severity);

        // The caller's own external id is namespaced per patient, so writing it
        // under A creates a second record rather than reaching A's twin under B.
        $this->putJson("/api/v1/patients/{$patientB->id}/allergies", $this->allergyPayload())
            ->assertCreated();
        $this->putJson("/api/v1/patients/{$patientA->id}/allergies", $this->allergyPayload())
            ->assertCreated();
        $this->assertSame(
            1,
            PhrAllergy::query()->where('patient_id', $patientA->id)->count(),
        );
        $this->assertSame(
            2,
            PhrAllergy::query()->where('patient_id', $patientB->id)->count(),
        );
    }

    // ── Absent context ────────────────────────────────────────────────────────

    public function test_an_absent_or_unusable_bearer_never_reaches_an_allergy(): void
    {
        $owner = $this->user('absent-owner@example.test');
        $patient = $this->patient($owner, 'Synthetic Absent Patient');
        $allergy = $this->seedAllergy($patient, $owner);

        $this->app->get('auth')->forgetGuards();

        $this->getJson("/api/v1/patients/{$patient->id}/allergies")->assertUnauthorized();
        $this->getJson("/api/v1/patients/{$patient->id}/allergies/{$allergy->id}")->assertUnauthorized();
        $this->putJson("/api/v1/patients/{$patient->id}/allergies", $this->allergyPayload())
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer synthetic-not-a-token')
            ->getJson("/api/v1/patients/{$patient->id}/allergies")
            ->assertUnauthorized();
    }

    /**
     * A disabled account's token stops working, and the refusal is an
     * authentication failure rather than a partial read.
     */
    public function test_an_actor_who_may_no_longer_log_in_loses_the_agent_surface(): void
    {
        // User id 1 is unconditionally an admin, so the actor under test must
        // not be the first row this test creates.
        $this->user('first-account@example.test');
        $owner = $this->user('disabled-owner@example.test');
        $this->assertNotSame(1, (int) $owner->id);
        $patient = $this->patient($owner, 'Synthetic Disabled Patient');
        $this->seedAllergy($patient, $owner);
        $client = $this->client('Synthetic Disabled Client');

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/allergies")->assertOk();

        $owner->forceFill(['user_role' => 'disabled'])->save();

        Passport::actingAs($owner->fresh(), [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/allergies")->assertUnauthorized();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function allergyPayload(): array
    {
        return [
            'external_id' => 'synthetic-allergy-001',
            'source_document_id' => null,
            'expected_version' => null,
            'data' => [
                'substance' => 'Synthetic substance A',
                'clinical_status' => 'active',
            ],
        ];
    }

    private function versionOf(?Model $record): string
    {
        $this->assertNotNull($record);

        return app(AgentClinicalRecordVersion::class)->for($record);
    }

    private function user(string $email): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Agent Actor',
            'email' => $email,
            'user_role' => 'user',
        ]);
    }

    private function patient(User $owner, string $displayName): PhrPatient
    {
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $displayName,
            'relationship' => 'self',
            'birth_date' => '2000-01-01',
            'sex_at_birth' => 'unknown',
        ]);
        $this->grant($patient, $owner, PhrPatientUserAccess::LEVEL_OWNER, $owner);

        return $patient;
    }

    private function grant(PhrPatient $patient, User $user, string $level, User $grantedBy): PhrPatientUserAccess
    {
        return PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $user->id,
            'access_level' => $level,
            'granted_by_user_id' => $grantedBy->id,
            'granted_at' => now(),
        ]);
    }

    private function seedAllergy(
        PhrPatient $patient,
        User $recordOwner,
        string $substance = 'Synthetic seeded substance',
    ): PhrAllergy {
        return PhrAllergy::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $recordOwner->id,
            'substance' => $substance,
            'clinical_status' => 'active',
        ]);
    }

    private function client(string $name): Client
    {
        return Client::query()->create([
            'name' => $name,
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }
}
