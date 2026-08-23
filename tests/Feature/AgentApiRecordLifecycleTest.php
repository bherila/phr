<?php

namespace Tests\Feature;

use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Export\PhrExportDataService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
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

    public function test_a_retracted_record_leaves_the_lists_and_the_export(): void
    {
        [$actor, $patient] = $this->agent('lifecycle-exclusion@example.test');

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())->assertCreated();
        $record = PhrOfficeVisit::query()->sole();
        // A human confirmed it before the source withdrew it. A confirmation is
        // not a licence to keep exporting a claim its source has taken back.
        $record->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();

        $this->getJson("/api/v1/patients/{$patient->id}/office-visits")
            ->assertOk()
            ->assertJsonPath('data.0.id', $record->id);
        $this->assertContains(
            $record->id,
            app(PhrExportDataService::class)->load($patient)['office_visits']->pluck('id')->all(),
        );

        $record->forceFill(['retracted_at' => now()])->save();

        $this->getJson("/api/v1/patients/{$patient->id}/office-visits")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($actor)
            ->getJson("/api/phr/patients/{$patient->id}/office-visits")
            ->assertOk()
            ->assertJsonCount(0, 'office_visits');
        $this->assertNotContains(
            $record->id,
            app(PhrExportDataService::class)->load($patient)['office_visits']->pluck('id')->all(),
        );

        // Search and timeline read the same models through their own query
        // builder, so they are a separate way in and need the same exclusion.
        $this->getJson("/api/v1/patients/{$patient->id}/records/search?resource_type[]=office-visits")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/patients/{$patient->id}/timeline?resource_type[]=office-visits")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_client_can_withdraw_its_own_record_and_repeating_it_is_settled(): void
    {
        [, $patient] = $this->agent('lifecycle-retract-own@example.test');

        $created = $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())
            ->assertCreated()
            ->json();
        $record = PhrOfficeVisit::query()->sole();
        $version = $created['version'];

        $retracted = $this->postJson(
            "/api/v1/patients/{$patient->id}/office-visits/{$record->id}/retract",
            ['expected_version' => $version],
        )->assertOk()
            ->assertJsonPath('outcome', 'retracted')
            ->assertJsonPath('lifecycle', 'retracted')
            ->json();

        // Withdrawn, not deleted: the row survives and keeps its identity.
        $this->assertSame(1, PhrOfficeVisit::query()->count());
        $this->assertNotNull(PhrOfficeVisit::query()->sole()->retracted_at);
        $this->getJson("/api/v1/patients/{$patient->id}/office-visits")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A retried call is the same request arriving twice, not a failure.
        $this->postJson(
            "/api/v1/patients/{$patient->id}/office-visits/{$record->id}/retract",
            ['expected_version' => $retracted['version']],
        )->assertOk()->assertJsonPath('outcome', 'unchanged');
    }

    public function test_retraction_requires_the_version_the_caller_is_holding(): void
    {
        [, $patient] = $this->agent('lifecycle-retract-version@example.test');

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())->assertCreated();
        $record = PhrOfficeVisit::query()->sole();

        $this->postJson(
            "/api/v1/patients/{$patient->id}/office-visits/{$record->id}/retract",
            ['expected_version' => str_repeat('b', 64)],
        )->assertConflict();

        $this->assertNull(PhrOfficeVisit::query()->sole()->retracted_at);
    }

    public function test_a_record_outside_the_callers_namespace_reports_as_missing(): void
    {
        [$actor, $patient] = $this->agent('lifecycle-retract-foreign@example.test');

        $created = $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())
            ->assertCreated()
            ->json();
        $record = PhrOfficeVisit::query()->sole();

        // A second integration holds a different import namespace. Refusing by
        // name would confirm that someone else wrote this record, so it reports
        // as missing instead.
        $other = Client::query()->create([
            'name' => 'Synthetic Other Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://other.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs($actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $other);

        $this->postJson(
            "/api/v1/patients/{$patient->id}/office-visits/{$record->id}/retract",
            ['expected_version' => $created['version']],
        )->assertNotFound();

        $this->assertNull(PhrOfficeVisit::query()->sole()->retracted_at);
    }

    public function test_a_browser_created_record_cannot_be_withdrawn_by_an_agent(): void
    {
        [$actor, $patient] = $this->agent('lifecycle-retract-browser@example.test');

        $record = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $actor->id,
            'visit_date' => '2026-02-01',
            'visit_type' => 'synthetic-browser-entry',
        ]);

        $this->postJson(
            "/api/v1/patients/{$patient->id}/office-visits/{$record->id}/retract",
            ['expected_version' => str_repeat('c', 64)],
        )->assertNotFound();

        $this->assertNull($record->fresh()?->retracted_at);
    }

    public function test_resolve_reports_withdrawn_records_rather_than_calling_the_id_free(): void
    {
        [$actor, $patient] = $this->agent('lifecycle-resolve@example.test');

        $this->putJson("/api/v1/patients/{$patient->id}/office-visits", $this->payload())->assertCreated();
        $record = PhrOfficeVisit::query()->sole();
        $externalId = $this->payload()['external_id'];

        $this->postJson("/api/v1/patients/{$patient->id}/office-visits/resolve", ['external_ids' => [$externalId]])
            ->assertOk()
            ->assertJsonPath("resolved.{$externalId}.lifecycle", 'active')
            ->assertJsonPath("resolved.{$externalId}.retracted_at", null)
            ->assertJsonPath('unresolved', []);

        $record->forceFill(['retracted_at' => now()])->save();

        $this->postJson("/api/v1/patients/{$patient->id}/office-visits/resolve", ['external_ids' => [$externalId]])
            ->assertOk()
            ->assertJsonPath("resolved.{$externalId}.lifecycle", 'retracted')
            ->assertJsonPath('unresolved', []);

        // A deletion is the case that matters for re-import: the identity is
        // still reserved, so reporting it as unresolved would invite the client
        // to re-add exactly what the person removed.
        $record->forceFill(['retracted_at' => null])->save();
        $this->actingAs($actor)
            ->deleteJson("/api/phr/patients/{$patient->id}/office-visits/{$record->id}")
            ->assertNoContent();

        $this->postJson("/api/v1/patients/{$patient->id}/office-visits/resolve", ['external_ids' => [$externalId]])
            ->assertOk()
            ->assertJsonPath("resolved.{$externalId}.lifecycle", 'deleted')
            ->assertJsonPath('unresolved', []);
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
