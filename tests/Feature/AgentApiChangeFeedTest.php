<?php

namespace Tests\Feature;

use App\Models\PhrMedication;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentApiChangeFeedTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_change_feed_uses_a_snapshot_watermark_and_returns_lifecycle_tombstones(): void
    {
        $owner = $this->user('change-feed-owner@example.test');
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Change Feed Patient']);
        $active = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'visit_type' => 'synthetic active change',
            'created_at' => '2030-01-01 00:00:00',
            'updated_at' => '2030-01-01 00:00:00',
        ]);
        $active->forceFill(['updated_at' => '2030-01-01 00:00:00'])->saveQuietly();
        $deleted = PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'visit_type' => 'synthetic deleted change',
            'created_at' => '2030-01-02 00:00:00',
            'updated_at' => '2030-01-02 00:00:00',
        ]);
        $deleted->delete();
        $deleted->forceFill(['updated_at' => '2030-01-02 00:00:00', 'deleted_at' => '2030-01-02 00:00:00'])->saveQuietly();
        $retracted = PhrMedication::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $owner->id,
            'name' => 'Synthetic retracted change',
            'created_at' => '2030-01-03 00:00:00',
            'updated_at' => '2030-01-03 00:00:00',
            'retracted_at' => '2030-01-03 00:00:00',
        ]);
        $retracted->forceFill(['updated_at' => '2030-01-03 00:00:00', 'retracted_at' => '2030-01-03 00:00:00'])->saveQuietly();
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ], 'api', $this->client());

        $base = "/api/v1/patients/{$patient->id}/changes?limit=1&updated_after=2029-12-31T00:00:00Z&watermark=2030-01-04T00:00:00Z";
        $first = $this->getJson($base)->assertOk()->json();
        $this->assertSame(1, count($first['data']));
        $this->assertTrue($first['pagination']['has_more']);
        $this->assertArrayHasKey('watermark', $first['pagination']);
        $this->assertContains($first['data'][0]['change_type'], ['upsert', 'deleted', 'retracted']);

        $second = $this->getJson($base.'&cursor='.urlencode((string) $first['pagination']['next_cursor']).'&watermark='.urlencode((string) $first['pagination']['watermark']))
            ->assertOk()
            ->assertJsonPath('pagination.watermark', $first['pagination']['watermark'])
            ->json();
        $third = $this->getJson($base.'&cursor='.urlencode((string) $second['pagination']['next_cursor']).'&watermark='.urlencode((string) $first['pagination']['watermark']))
            ->assertOk()
            ->assertJsonPath('pagination.has_more', false)
            ->json();
        $changes = [...$first['data'], ...$second['data'], ...$third['data']];

        $this->assertSame(3, count($changes));
        $byIdentity = collect($changes)->keyBy(fn (array $change): string => $change['resource_type'].':'.$change['id']);
        $this->assertSame('upsert', $byIdentity['office-visits:'.$active->id]['change_type']);
        $this->assertSame('deleted', $byIdentity['office-visits:'.$deleted->id]['change_type']);
        $this->assertArrayNotHasKey('data', $byIdentity['office-visits:'.$deleted->id]);
        $this->assertSame('retracted', $byIdentity['medications:'.$retracted->id]['change_type']);
        $this->assertArrayNotHasKey('data', $byIdentity['medications:'.$retracted->id]);
    }

    public function test_change_feed_requires_clinical_read_and_hides_other_patients(): void
    {
        $owner = $this->user('change-feed-owner-denial@example.test');
        $other = $this->user('change-feed-other-denial@example.test');
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Feed Patient']);
        $hidden = PhrPatient::query()->create(['owner_user_id' => $other->id, 'display_name' => 'Synthetic Hidden Feed Patient']);
        $client = $this->client();

        Passport::actingAs($owner, [AgentApiScopes::PATIENTS_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$patient->id}/changes")->assertForbidden();
        Passport::actingAs($owner, [AgentApiScopes::CLINICAL_READ], 'api', $client);
        $this->getJson("/api/v1/patients/{$hidden->id}/changes")->assertNotFound();
    }

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email, 'user_role' => 'user']);
    }

    private function client(): Client
    {
        return Client::factory()->create(['name' => 'Synthetic Change Feed Agent']);
    }
}
