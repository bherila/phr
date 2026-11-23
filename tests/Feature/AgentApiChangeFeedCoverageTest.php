<?php

namespace Tests\Feature;

use App\Models\PhrEob;
use App\Models\PhrHealthLog;
use App\Models\PhrHealthLogEntry;
use App\Models\PhrMedication;
use App\Models\PhrMedicationTransition;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentRecordSearchCatalog;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * changes.list follows medication transitions, EOBs, health logs and their entries (#444),
 * with a tombstone for each delete, and records.search and timeline.list read them too.
 * Every value is synthetic.
 */
final class AgentApiChangeFeedCoverageTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const array TYPES = ['medication-transitions', 'eobs', 'health-logs', 'health-log-entries'];

    private User $owner;

    private PhrPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        $this->travelTo(Carbon::parse('2026-03-04 05:06:07'));
        $this->owner = $this->createUser();
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->owner->id, 'display_name' => 'Synthetic Patient']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->owner->id, 'granted_at' => now(),
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Feed Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->owner, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE], 'api', $client);
    }

    public function test_the_feed_catalog_names_every_followed_type_within_its_bound(): void
    {
        foreach (self::TYPES as $type) {
            $this->assertContains($type, AgentRecordSearchCatalog::ids());
        }
        $this->assertCount(AgentRecordSearchCatalog::RESOURCE_TYPE_COUNT, AgentRecordSearchCatalog::ids());
        // Every type but documents, which needs documents:read too.
        $this->changes(array_values(array_diff(AgentRecordSearchCatalog::ids(), ['documents'])))->assertOk();
    }

    public function test_each_type_reports_its_upsert_and_then_its_tombstone(): void
    {
        $records = $this->records();
        $upserts = collect($this->changes()->assertOk()->json('data'))->where('change_type', 'upsert');
        foreach ($records as $type => $id) {
            $this->assertNotNull($upserts->first(static fn (array $change): bool => $change['resource_type'] === $type && $change['id'] === $id), $type);
        }
        $transition = $upserts->firstWhere('resource_type', 'medication-transitions');
        $this->assertSame('Held at admission', $transition['data']['summary']);
        $this->assertSame('Synthetic Pharmacy Plan', $upserts->firstWhere('resource_type', 'eobs')['data']['summary']);
        $this->assertSame('Synthetic entry', $upserts->firstWhere('resource_type', 'health-log-entries')['data']['summary']);

        $this->travel(1)->minutes();
        $read = fn (string $path): string => (string) $this->getJson("/api/v1/patients/{$this->patient->id}/{$path}")->assertOk()->json('data.version');
        $this->deleteJson("/api/v1/patients/{$this->patient->id}/medication-transitions/{$records['medication-transitions']}", ['expected_version' => $read("medication-transitions/{$records['medication-transitions']}")])->assertOk();
        $this->deleteJson("/api/v1/patients/{$this->patient->id}/eobs/{$records['eobs']}", ['expected_version' => $read("eobs/{$records['eobs']}")])->assertOk();
        // The log takes its entry with it.
        $this->deleteJson("/api/v1/patients/{$this->patient->id}/health-logs/{$records['health-logs']}", ['expected_version' => $read("health-logs/{$records['health-logs']}")])->assertOk();

        $changes = $this->changes()->assertOk()->json('data');
        foreach ($records as $type => $id) {
            $this->assertContains(['resource_type' => $type, 'id' => $id, 'patient_id' => $this->patient->id, 'change_type' => 'deleted', 'changed_at' => now()->toIso8601String()], $changes, $type);
        }
        // Gone from search and the timeline.
        $this->assertSame([], $this->getJson("/api/v1/patients/{$this->patient->id}/timeline?".http_build_query(['resource_type' => self::TYPES]))->assertOk()->json('data'));
    }

    public function test_search_and_the_timeline_read_the_new_types(): void
    {
        $records = $this->records();
        $timeline = collect($this->getJson("/api/v1/patients/{$this->patient->id}/timeline?".http_build_query(['resource_type' => self::TYPES]))->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(array_values($records), $timeline->pluck('id')->all());
        // An entry is dated when it happened, a claim when it was processed.
        $this->assertSame('2026-02-01T09:30:00+00:00', $timeline->firstWhere('resource_type', 'health-log-entries')['event_at']);
        $this->assertStringStartsWith('2026-02-10', $timeline->firstWhere('resource_type', 'eobs')['event_at']);

        $found = $this->getJson("/api/v1/patients/{$this->patient->id}/records/search?".http_build_query(['q' => 'pharmacy plan', 'resource_type' => ['eobs']]))->assertOk()->json('data');
        $this->assertSame([$records['eobs']], array_column($found, 'id'));
        $found = $this->getJson("/api/v1/patients/{$this->patient->id}/records/search?".http_build_query(['q' => 'kidney', 'resource_type' => ['medication-transitions']]))->assertOk()->json('data');
        $this->assertSame([$records['medication-transitions']], array_column($found, 'id'));
    }

    /** @return array<string, int> one record of each type, by type */
    private function records(): array
    {
        $base = ['patient_id' => $this->patient->id, 'user_id' => $this->owner->id];
        $visit = PhrOfficeVisit::query()->create([...$base, 'visit_date' => '2026-02-09', 'encounter_class' => 'IMP']);
        $medication = PhrMedication::query()->create([...$base, 'name' => 'Synthetic home medication 10 mg oral tablet', 'status' => 'active', 'category' => 'community', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $transition = PhrMedicationTransition::query()->create([...$base, 'office_visit_id' => $visit->id, 'home_medication_id' => $medication->id, 'phase' => 'admission', 'action' => 'held', 'note' => 'Held for synthetic kidney injury', 'review_status' => PhrReviewStatus::CONFIRMED]);
        $eob = PhrEob::query()->create([...$base, 'import_source' => 'synthetic', 'external_id' => 'eob-1', 'claim_number' => 'RX-1', 'claim_type' => 'medical', 'provider_name' => 'Synthetic Pharmacy Plan', 'processed_date' => '2026-02-10', 'review_status' => PhrReviewStatus::PENDING]);
        $log = PhrHealthLog::query()->create([...$base, 'name' => 'Synthetic log', 'kind' => 'custom']);
        $entry = PhrHealthLogEntry::query()->create([...$base, 'health_log_id' => $log->id, 'occurred_at' => '2026-02-01 09:30:00', 'title' => 'Synthetic entry']);

        return ['medication-transitions' => (int) $transition->id, 'eobs' => (int) $eob->id, 'health-logs' => (int) $log->id, 'health-log-entries' => (int) $entry->id];
    }

    /** @param  list<string>  $types */
    private function changes(array $types = self::TYPES): TestResponse
    {
        return $this->getJson("/api/v1/patients/{$this->patient->id}/changes?".http_build_query(['resource_type' => $types]));
    }
}
