<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiReconciliationTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_reconciliation_requires_its_own_scopes_and_applies_only_the_confirmed_preview(): void
    {
        $owner = $this->user('reconciliation-owner@example.test');
        $viewer = $this->user('reconciliation-viewer@example.test');
        $patient = $this->patient($owner);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $viewer->id,
            'access_level' => PhrPatientUserAccess::LEVEL_VIEWER,
            'granted_by_user_id' => $owner->id,
            'granted_at' => now(),
        ]);
        $eob = PhrEob::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'import_source' => 'meritain_eob',
            'external_id' => 'synthetic-reconciliation-eob',
            'claim_fingerprint' => hash('sha256', 'synthetic-reconciliation-eob'),
            'claim_type' => 'medical',
            'provider_name' => 'Synthetic Reconciliation Clinic',
            'processed_date' => '2030-01-03',
        ]);
        PhrEobLine::query()->create([
            'eob_id' => $eob->id,
            'patient_id' => $patient->id,
            'line_number' => 1,
            'procedure_code' => '99214',
            'code_type' => 'cpt',
            'service_start' => '2030-01-02',
            'service_end' => '2030-01-02',
        ]);
        $client = $this->client();
        $path = "/api/v1/patients/{$patient->id}/reconciliations/meritain-eob-visits";

        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("{$path}/preview")->assertForbidden();

        Passport::actingAs($viewer, [AgentApiScopes::RECONCILIATION_READ], 'api', $client);
        $preview = $this->getJson("{$path}/preview")
            ->assertOk()
            ->assertJsonPath('resource_type', 'reconciliation_preview')
            ->assertJsonPath('data.summary.created', 1)
            ->assertJsonPath('data.summary.links', 1)
            ->json();
        $this->assertDatabaseCount('phr_office_visits', 0);

        Passport::actingAs($viewer, [AgentApiScopes::RECONCILIATION_WRITE], 'api', $client);
        $this->postJson("{$path}/apply", ['preview_digest' => $preview['data']['preview_digest']])->assertForbidden();

        Passport::actingAs($owner, [AgentApiScopes::RECONCILIATION_WRITE], 'api', $client);
        $this->postJson("{$path}/apply", ['preview_digest' => $preview['data']['preview_digest']])
            ->assertOk()
            ->assertJsonPath('resource_type', 'reconciliation_apply')
            ->assertJsonPath('outcome', 'applied')
            ->assertJsonPath('data.summary.created', 1);
        $this->assertDatabaseCount('phr_office_visits', 1);
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.reconciliations.apply',
            'response_status' => 200,
        ]);

        $this->postJson("{$path}/apply", ['preview_digest' => $preview['data']['preview_digest']])
            ->assertConflict();
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.reconciliations.apply',
            'response_status' => 409,
        ]);
        $this->assertStringNotContainsString('Synthetic Reconciliation Clinic', (string) AgentApiAudit::query()->get()->toJson());
    }

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email, 'user_role' => 'user']);
    }

    private function patient(User $owner): PhrPatient
    {
        return PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Synthetic Reconciliation Patient',
        ]);
    }

    private function client(): Client
    {
        return Client::factory()->create(['name' => 'Synthetic Reconciliation Agent']);
    }
}
