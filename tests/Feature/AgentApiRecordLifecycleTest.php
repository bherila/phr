<?php

namespace Tests\Feature;

use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * A withdrawn record keeps its identity, and an agent may not walk it back.
 */
final class AgentApiRecordLifecycleTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_a_browser_deletion_is_recorded_rather_than_erased(): void
    {
        [$actor, $patient] = $this->agent('lifecycle-delete@example.test');

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())->assertCreated();
        $record = PhrOfficeVisit::query()->sole();

        $this->actingAs($actor)
            ->deleteJson("/api/phr/patients/{$patient->id}/office-visits/{$record->id}")
            ->assertNoContent();

        // The row survives, which is the whole point: a hard delete is invisible
        // to a mirroring client and can never be reconciled.
        $this->assertSame(0, PhrOfficeVisit::query()->count());
        $this->assertSame(1, PhrOfficeVisit::withTrashed()->count());
        $this->assertNotNull(PhrOfficeVisit::withTrashed()->sole()->deleted_at);
    }

    public function test_an_agent_cannot_resurrect_an_identity_a_person_deleted(): void
    {
        [$actor, $patient, $client] = $this->agent('lifecycle-resurrect@example.test');

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())->assertCreated();
        $record = PhrOfficeVisit::query()->sole();
        $this->actingAs($actor)
            ->deleteJson("/api/phr/patients/{$patient->id}/office-visits/{$record->id}")
            ->assertNoContent();

        // The unique identity index still holds the slot, so an ordinary upsert
        // must conflict rather than revive the record or fail on the constraint.
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())
            ->assertConflict();

        $this->assertSame(0, PhrOfficeVisit::query()->count());
        $this->assertSame(1, PhrOfficeVisit::withTrashed()->count());
        $this->assertNotNull(PhrOfficeVisit::withTrashed()->sole()->deleted_at);
    }

    public function test_a_retracted_identity_is_not_reusable_either(): void
    {
        [$actor, $patient, $client] = $this->agent('lifecycle-retracted@example.test');

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())->assertCreated();
        PhrOfficeVisit::query()->sole()->forceFill(['retracted_at' => now()])->save();

        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);
        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())
            ->assertConflict();

        $this->assertSame(1, PhrOfficeVisit::query()->count());
        $this->assertNotNull(PhrOfficeVisit::query()->sole()->retracted_at);
    }

    /** @return array{0: User, 1: PhrPatient, 2: Client} */
    private function agent(string $email): array
    {
        $actor = User::factory()->create([
            'name' => 'Synthetic Lifecycle User',
            'email' => $email,
            'user_role' => 'user',
        ]);
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $actor->id,
            'display_name' => 'Synthetic Lifecycle Patient',
            'relationship' => 'self',
            'birth_date' => '2000-01-01',
            'sex_at_birth' => 'unknown',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $actor->id,
            'granted_at' => now(),
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Lifecycle Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $client);

        return [$actor, $patient, $client];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'external_id' => 'synthetic-lifecycle-001',
            'source_document_id' => null,
            'expected_version' => null,
            'data' => [
                'visit_date' => '2026-01-15',
                'visit_type' => 'synthetic-follow-up',
                'assessment' => 'Synthetic lifecycle assessment',
            ],
        ];
    }
}
