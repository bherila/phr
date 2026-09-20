<?php

namespace Tests\Feature\PHR;

use App\Models\PhrAllergy;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

/**
 * Characterization of the current session-authenticated authorization boundary
 * for the patient + allergy pilot.
 *
 * These tests do not propose behaviour. They pin what the application does today
 * so that a later move of the same decisions behind a shared privacy package can
 * be shown to preserve it. Every expectation here was read off the current
 * implementation and the existing suite, never off a role name.
 *
 * Three distinctions carry the weight and are asserted separately:
 *
 *  - readable   — any grant row for the actor, at any level.
 *  - writable   — owner_user_id, or a grant at owner/manager level.
 *  - actual-owner-only — owner_user_id equals the actor, and nothing else.
 *
 * A grant labelled `owner` is deliberately *not* treated as proof of ownership,
 * so sharing, export, native backup and patient deletion stay with the real
 * owner even if such a row exists. All fixtures are synthetic.
 */
class PhrPatientAllergyAuthorizationCharacterizationTest extends TestCase
{
    // ── Owner ─────────────────────────────────────────────────────────────────

    public function test_owner_can_read_write_review_and_delete_an_allergy(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->pilotPatient();

        $allergyId = (int) $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/allergies", [
                'substance' => 'Synthetic substance A',
                'clinical_status' => 'active',
            ])
            ->assertCreated()
            ->json('allergy.id');

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/allergies")
            ->assertOk()
            ->assertJsonCount(1, 'allergies')
            ->assertJsonPath('can_manage', true);

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertOk()
            ->assertJsonPath('allergy.substance', 'Synthetic substance A')
            ->assertJsonPath('can_manage', true);

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}", [
            'clinical_status' => 'inactive',
        ])->assertOk()->assertJsonPath('allergy.clinical_status', 'inactive');

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}/review", [
            'review_status' => PhrReviewStatus::CONFIRMED,
        ])->assertOk()->assertJsonPath('allergy.review_status', PhrReviewStatus::CONFIRMED);

        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertNoContent();

        // Deletion is soft, and the soft-deleted row leaves every read path.
        $this->assertSame(0, PhrAllergy::query()->count());
        $this->assertSame(1, PhrAllergy::withTrashed()->count());
    }

    // ── Reader ────────────────────────────────────────────────────────────────

    public function test_a_read_only_grant_reads_allergies_but_never_writes_them(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->pilotPatient();
        $allergyId = $this->seedAllergy($patientId, $owner);

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/allergies")
            ->assertOk()
            ->assertJsonCount(1, 'allergies')
            ->assertJsonPath('can_manage', false);

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertOk()
            ->assertJsonPath('can_manage', false);

        // Read sharing is not write permission, on any of the four write verbs.
        $this->actingAs($viewer)->postJson("/api/phr/patients/{$patientId}/allergies", [
            'substance' => 'Synthetic substance B',
        ])->assertForbidden();

        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}", [
            'clinical_status' => 'resolved',
        ])->assertForbidden();

        $this->actingAs($viewer)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}/review", [
            'review_status' => PhrReviewStatus::CONFIRMED,
        ])->assertForbidden();

        $this->actingAs($viewer)->deleteJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertForbidden();

        $this->assertSame(1, PhrAllergy::query()->where('patient_id', $patientId)->count());
    }

    // ── Writable manager ──────────────────────────────────────────────────────

    public function test_a_writable_manager_manages_allergies_but_holds_no_owner_only_power(): void
    {
        ['owner' => $owner, 'manager' => $manager, 'patientId' => $patientId] = $this->pilotPatient();

        $allergyId = (int) $this->actingAs($manager)
            ->postJson("/api/phr/patients/{$patientId}/allergies", [
                'substance' => 'Synthetic substance C',
            ])
            ->assertCreated()
            ->json('allergy.id');

        $this->actingAs($manager)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}/review", [
            'review_status' => PhrReviewStatus::REJECTED,
        ])->assertOk();

        // Write access does not carry re-sharing, export, backup or deletion.
        $stranger = $this->createUser(['email' => 'outsider@example.test']);
        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => $stranger->email,
            'access_level' => 'viewer',
        ])->assertForbidden();

        $ownerGrantId = (int) PhrPatientUserAccess::query()
            ->where('patient_id', $patientId)
            ->where('user_id', $owner->id)
            ->value('id');
        $this->actingAs($manager)
            ->deleteJson("/api/phr/patients/{$patientId}/access/{$ownerGrantId}")
            ->assertForbidden();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/exports", [
            'format' => 'fhir',
        ])->assertForbidden();

        $this->actingAs($manager)->postJson("/api/phr/patients/{$patientId}/native-backups")
            ->assertForbidden();

        $this->actingAs($manager)
            ->getJson("/api/phr/data-hub/patients/{$patientId}/deletion-preview")
            ->assertForbidden();

        $this->assertDatabaseMissing('phr_patient_user_access', [
            'patient_id' => $patientId,
            'user_id' => $stranger->id,
        ]);
    }

    // ── Actual-owner-only ─────────────────────────────────────────────────────

    /**
     * The pivotal distinction: a grant row whose label is `owner` makes the
     * holder writable, and nothing more. Operations that compare the actor to
     * `phr_patients.owner_user_id` still refuse, so the grant label can never
     * stand in for ownership.
     *
     * No supported writer produces such a row today — the sharing request only
     * accepts manager/viewer and the native-restore planner blocks an
     * owner-level share — so it is built directly here, as a synthetic fixture,
     * to pin the service's behaviour rather than the reachability of the row.
     */
    public function test_an_owner_labelled_grant_is_writable_but_is_not_ownership(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->pilotPatient();
        $deputy = $this->createUser(['email' => 'deputy@example.test']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patientId,
            'user_id' => $deputy->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);

        $service = app(PhrPatientAccessService::class);
        $patient = PhrPatient::query()->findOrFail($patientId);

        $this->assertTrue($service->canWrite($patient, (int) $deputy->id));
        $service->ensureCanWrite($patient, (int) $deputy->id);

        try {
            $service->ensureOwner($patient, (int) $deputy->id);
            $this->fail('An owner-labelled grant must not satisfy an actual-owner-only check.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // The HTTP surface agrees: allergies yes, owner-only operations no.
        $this->actingAs($deputy)->postJson("/api/phr/patients/{$patientId}/allergies", [
            'substance' => 'Synthetic substance D',
        ])->assertCreated();

        $this->actingAs($deputy)->postJson("/api/phr/patients/{$patientId}/access", [
            'email' => 'nobody@example.test',
            'access_level' => 'viewer',
        ])->assertForbidden();

        $this->actingAs($deputy)->postJson("/api/phr/patients/{$patientId}/native-backups")
            ->assertForbidden();

        // ownedPatientsQuery is the query-shaped form of the same rule.
        $this->assertSame(
            0,
            $service->ownedPatientsQuery((int) $deputy->id)->count(),
            'An owner-labelled grant must not appear as an owned patient.',
        );
        $this->assertSame(1, $service->ownedPatientsQuery((int) $owner->id)->count());
        $this->assertSame(1, $service->writablePatientsQuery((int) $deputy->id)->count());
    }

    // ── Revoked / no grant ────────────────────────────────────────────────────

    public function test_a_revoked_grant_and_a_user_with_no_grant_are_both_answered_as_not_found(): void
    {
        ['owner' => $owner, 'viewer' => $viewer, 'patientId' => $patientId] = $this->pilotPatient();
        $allergyId = $this->seedAllergy($patientId, $owner);
        $stranger = $this->createUser(['email' => 'stranger@example.test']);

        // A user with no grant must not learn the patient or the record exists.
        $this->actingAs($stranger)->getJson("/api/phr/patients/{$patientId}/allergies")
            ->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertNotFound();
        $this->actingAs($stranger)->postJson("/api/phr/patients/{$patientId}/allergies", [
            'substance' => 'Synthetic substance E',
        ])->assertNotFound();

        // The reader's access ends with its grant row, on the next request.
        $grantId = (int) PhrPatientUserAccess::query()
            ->where('patient_id', $patientId)
            ->where('user_id', $viewer->id)
            ->value('id');
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientId}/access/{$grantId}")
            ->assertOk();

        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/allergies")
            ->assertNotFound();
        $this->actingAs($viewer)->getJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertNotFound();

        // A denied lookup discloses nothing about the record it refused.
        $denied = (string) $this->actingAs($viewer)
            ->getJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->getContent();
        $this->assertStringNotContainsString('Synthetic seeded substance', $denied);
    }

    // ── Wrong patient/child pair, and two accessible patients ─────────────────

    /**
     * Patient ownership is the resource boundary. An allergy requested under
     * patient A must actually belong to A, even when the same actor can reach
     * both patients — so the pair, not the record id alone, is what resolves.
     */
    public function test_an_allergy_never_resolves_under_a_patient_it_does_not_belong_to(): void
    {
        ['owner' => $owner, 'patientId' => $patientA] = $this->pilotPatient();
        $secondOwner = $this->createUser(['email' => 'second-owner@example.test']);
        $patientB = $this->createPatientFor($secondOwner, 'Synthetic Patient B');
        $this->grant($secondOwner, $patientB, $owner->email, 'manager');

        $allergyA = $this->seedAllergy($patientA, $owner, 'Synthetic substance in A');
        $allergyB = $this->seedAllergy($patientB, $secondOwner, 'Synthetic substance in B');

        // Both patients are genuinely reachable by this one actor.
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientA}/allergies")
            ->assertOk()
            ->assertJsonCount(1, 'allergies')
            ->assertJsonPath('allergies.0.id', $allergyA);
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientB}/allergies")
            ->assertOk()
            ->assertJsonCount(1, 'allergies')
            ->assertJsonPath('allergies.0.id', $allergyB);

        // The mismatched pair still refuses, on read and on every write verb.
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientA}/allergies/{$allergyB}")
            ->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientA}/allergies/{$allergyB}", [
            'clinical_status' => 'resolved',
        ])->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientA}/allergies/{$allergyB}/review", [
            'review_status' => PhrReviewStatus::CONFIRMED,
        ])->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patientA}/allergies/{$allergyB}")
            ->assertNotFound();

        $this->assertSame('active', PhrAllergy::query()->findOrFail($allergyB)->clinical_status);
        $this->assertSame(
            PhrReviewStatus::CONFIRMED,
            PhrAllergy::query()->findOrFail($allergyB)->review_status,
            'The seeded default must be unchanged by the refused review.',
        );
    }

    // ── Absent context ────────────────────────────────────────────────────────

    public function test_every_allergy_route_answers_an_unauthenticated_caller_with_401_json(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->pilotPatient();
        $allergyId = $this->seedAllergy($patientId, $owner);

        // Building the fixture authenticated the test's guard. Drop it, so the
        // calls below really do arrive with no actor context at all.
        $this->app->get('auth')->forgetGuards();

        $base = "/api/phr/patients/{$patientId}/allergies";
        $calls = [
            ['getJson', $base, []],
            ['postJson', $base, ['substance' => 'Synthetic substance F']],
            ['getJson', "{$base}/{$allergyId}", []],
            ['patchJson', "{$base}/{$allergyId}", ['clinical_status' => 'resolved']],
            ['patchJson', "{$base}/{$allergyId}/review", ['review_status' => PhrReviewStatus::CONFIRMED]],
            ['deleteJson', "{$base}/{$allergyId}", []],
        ];

        foreach ($calls as [$method, $url, $payload]) {
            $this->{$method}($url, $payload)
                ->assertStatus(401)
                ->assertJson(['message' => 'Unauthenticated.']);
        }

        // Nothing was created, changed or removed by the refused calls.
        $this->assertSame(1, PhrAllergy::query()->where('patient_id', $patientId)->count());
    }

    // ── Clinical review restrictions ──────────────────────────────────────────

    public function test_the_review_endpoint_records_only_a_human_decision(): void
    {
        ['owner' => $owner, 'patientId' => $patientId] = $this->pilotPatient();
        $allergyId = $this->seedAllergy($patientId, $owner);

        // `pending_review` is a state the server assigns; a reviewer cannot
        // put a record back into the queue by hand.
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}/review", [
            'review_status' => PhrReviewStatus::PENDING,
        ])->assertUnprocessable()->assertJsonValidationErrors('review_status');

        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}/review", [
            'review_status' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors('review_status');

        // A rejected record leaves the working list but stays reachable on request.
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}/review", [
            'review_status' => PhrReviewStatus::REJECTED,
        ])->assertOk();

        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/allergies")
            ->assertOk()
            ->assertJsonCount(0, 'allergies');
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/allergies?include_rejected=1")
            ->assertOk()
            ->assertJsonCount(1, 'allergies');
        $this->actingAs($owner)->getJson("/api/phr/patients/{$patientId}/allergies/{$allergyId}")
            ->assertOk()
            ->assertJsonPath('allergy.review_status', PhrReviewStatus::REJECTED);
    }

    // ── Mass assignment of scope fields ───────────────────────────────────────

    /**
     * `patient_id`, `user_id` and `review_status` are all fillable on the model,
     * so the guard is the validated-field allow-list rather than `$fillable`.
     * Create binds the pair from the resolved patient and spreads the validated
     * payload after it, which makes the allow-list load-bearing on both verbs.
     */
    public function test_scope_fields_cannot_be_mass_assigned_through_create_or_update(): void
    {
        ['owner' => $owner, 'patientId' => $patientA] = $this->pilotPatient();
        $secondOwner = $this->createUser(['email' => 'reparent-target@example.test']);
        $patientB = $this->createPatientFor($secondOwner, 'Synthetic Patient B');
        $this->grant($secondOwner, $patientB, $owner->email, 'manager');
        $intruder = $this->createUser(['email' => 'intruder@example.test']);

        $created = $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientA}/allergies", [
                'substance' => 'Synthetic substance G',
                'patient_id' => $patientB,
                'user_id' => $intruder->id,
                'review_status' => PhrReviewStatus::CONFIRMED,
                'import_source' => 'synthetic-forged-source',
                'external_id' => 'synthetic-forged-external-id',
                'source_document_id' => 999999,
            ])
            ->assertCreated()
            ->json('allergy');

        $this->assertSame($patientA, $created['patient_id']);
        $this->assertSame((int) $owner->id, $created['user_id']);
        $this->assertNull($created['import_source']);
        $this->assertNull($created['external_id']);
        $this->assertNull($created['source_document_id']);

        $allergyId = (int) $created['id'];

        // Reparenting an existing record is equally refused: the record stays
        // under the patient it was resolved through.
        $this->actingAs($owner)
            ->patchJson("/api/phr/patients/{$patientA}/allergies/{$allergyId}", [
                'substance' => 'Synthetic substance G',
                'patient_id' => $patientB,
                'user_id' => $intruder->id,
                'review_status' => PhrReviewStatus::CONFIRMED,
            ])
            ->assertOk();

        $stored = PhrAllergy::query()->findOrFail($allergyId);
        $this->assertSame($patientA, (int) $stored->patient_id);
        $this->assertSame((int) $owner->id, (int) $stored->user_id);

        // The patient record itself refuses the same trick: a writable manager
        // cannot rewrite owner_user_id through the patient update endpoint.
        $this->actingAs($owner)->patchJson("/api/phr/patients/{$patientB}", [
            'display_name' => 'Synthetic Patient B',
            'owner_user_id' => $owner->id,
        ])->assertOk();
        $this->assertSame(
            (int) $secondOwner->id,
            (int) PhrPatient::query()->findOrFail($patientB)->owner_user_id,
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @return array{owner: User, manager: User, viewer: User, patientId: int}
     */
    private function pilotPatient(): array
    {
        $owner = $this->createUser(['email' => 'pilot-owner@example.test']);
        $manager = $this->createUser(['email' => 'pilot-manager@example.test']);
        $viewer = $this->createUser(['email' => 'pilot-viewer@example.test']);

        $patientId = $this->createPatientFor($owner, 'Synthetic Pilot Patient');
        $this->grant($owner, $patientId, $manager->email, 'manager');
        $this->grant($owner, $patientId, $viewer->email, 'viewer');

        return compact('owner', 'manager', 'viewer', 'patientId');
    }

    private function createPatientFor(User $owner, string $displayName): int
    {
        return (int) $this->actingAs($owner)
            ->postJson('/api/phr/patients', [
                'display_name' => $displayName,
                'relationship' => 'self',
            ])
            ->assertCreated()
            ->json('patient.id');
    }

    private function grant(User $owner, int $patientId, string $email, string $level): void
    {
        $this->actingAs($owner)
            ->postJson("/api/phr/patients/{$patientId}/access", [
                'email' => $email,
                'access_level' => $level,
            ])
            ->assertCreated();
    }

    private function seedAllergy(int $patientId, User $recordOwner, string $substance = 'Synthetic seeded substance'): int
    {
        return (int) PhrAllergy::query()->create([
            'patient_id' => $patientId,
            'user_id' => $recordOwner->id,
            'substance' => $substance,
            'clinical_status' => 'active',
        ])->id;
    }
}
