<?php

namespace Tests\Feature\PHR;

use App\Models\PhrEpisode;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Encounters\PhrEpisodeLinks;
use App\Services\PHR\Export\PhrExportDataService;
use App\Services\PHR\Export\PhrFhirExporter;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * Episodes of care (#415): an agent groups the synthetic current stay and the earlier
 * pneumonia admission of #405 into one episode, a person reviews it, and only a confirmed
 * episode leaves in a FHIR export. Every value is synthetic; no real data.
 */
final class PhrEpisodesTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private string $session;

    private int $nextId = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_an_agent_groups_the_two_synthetic_stays_into_one_episode_over_mcp(): void
    {
        [, $patient] = $this->mcpSession();
        [$earlier, $current] = $this->upsertStays($patient);

        $episodeArguments = $this->episodeArguments($patient);
        $created = $this->tool('episodes.upsert', $episodeArguments);
        $this->assertFalse($created['isError'] ?? false, json_encode($created));
        $this->assertSame('created', $created['structuredContent']['outcome']);
        $this->assertSame(PhrReviewStatus::PENDING, $created['structuredContent']['data']['review_status']);
        $this->assertSame([], $created['structuredContent']['data']['encounter_ids']);
        $episodeId = (int) $created['structuredContent']['data']['id'];

        // Upserting the same episode again is a retry, not a second episode.
        $again = $this->tool('episodes.upsert', $episodeArguments);
        $this->assertSame('unchanged', $again['structuredContent']['outcome']);
        $this->assertSame($created['structuredContent']['version'], $again['structuredContent']['version']);
        $this->assertSame(1, PhrEpisode::query()->where('patient_id', $patient->id)->count());
        $resolved = $this->tool('clinical.resolve', ['resource_type' => 'episodes', 'patient_id' => $patient->id, 'external_ids' => ['synthetic-episode-pneumonia']]);
        $this->assertSame($episodeId, $resolved['structuredContent']['resolved']['synthetic-episode-pneumonia']['id']);

        foreach ([$current, $earlier] as $visitId) {
            $linked = $this->tool('episodes.link_encounter', ['patient_id' => $patient->id, 'episode_id' => $episodeId, 'visit_id' => $visitId]);
            $this->assertFalse($linked['isError'] ?? false, json_encode($linked));
            $this->assertSame('linked', $linked['structuredContent']['outcome']);
            $this->assertSame(['episode_id' => $episodeId, 'office_visit_id' => $visitId], $linked['structuredContent']['data']);
        }
        $this->assertSame('unchanged', $this->tool('episodes.link_encounter', ['patient_id' => $patient->id, 'episode_id' => $episodeId, 'visit_id' => $current])['structuredContent']['outcome']);

        // The read lists both stays, earliest first. A link is evidence: version and review stay.
        $read = $this->tool('clinical.get', ['resource_type' => 'episodes', 'patient_id' => $patient->id, 'record_id' => $episodeId])['structuredContent']['data'];
        $this->assertSame([$earlier, $current], $read['encounter_ids']);
        $this->assertSame($created['structuredContent']['version'], $read['version']);
        $this->assertSame(PhrReviewStatus::PENDING, $read['review_status']);
        $this->assertSame('2025-11-03 00:00:00', $read['started_at']);
        $listed = $this->tool('clinical.list', ['resource_type' => 'episodes', 'patient_id' => $patient->id])['structuredContent']['data'];
        $this->assertSame([$earlier, $current], $listed[0]['encounter_ids']);
        $search = $this->tool('records.search', ['patient_id' => $patient->id, 'q' => 'pneumonia', 'resource_type' => ['episodes']])['structuredContent']['data'];
        $this->assertSame([$episodeId], array_column($search, 'id'));
        $changes = $this->tool('changes.list', ['patient_id' => $patient->id, 'resource_type' => ['episodes']])['structuredContent']['data'];
        $this->assertSame(['episodes'], array_values(array_unique(array_column($changes, 'resource_type'))));

        // Taking a stay out and back in.
        $this->assertSame('unlinked', $this->tool('episodes.unlink_encounter', ['patient_id' => $patient->id, 'episode_id' => $episodeId, 'visit_id' => $earlier])['structuredContent']['outcome']);
        $this->assertSame('unchanged', $this->tool('episodes.unlink_encounter', ['patient_id' => $patient->id, 'episode_id' => $episodeId, 'visit_id' => $earlier])['structuredContent']['outcome']);
        $this->assertSame([$current], $this->tool('clinical.get', ['resource_type' => 'episodes', 'patient_id' => $patient->id, 'record_id' => $episodeId])['structuredContent']['data']['encounter_ids']);
        $this->assertSame('linked', $this->tool('episodes.link_encounter', ['patient_id' => $patient->id, 'episode_id' => $episodeId, 'visit_id' => $earlier])['structuredContent']['outcome']);
        $this->assertSame(2, DB::table('phr_episode_encounters')->where('episode_id', $episodeId)->count());
    }

    public function test_links_refuse_another_patients_visit_and_a_rejected_episode(): void
    {
        [, $patient] = $this->restSession([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $episode = $this->episode($patient, PhrReviewStatus::PENDING);
        $visit = $this->visit($patient, '2026-02-09', PhrReviewStatus::PENDING);
        $stranger = $this->patient('Other Example');
        $elsewhere = $this->visit($stranger, '2026-02-09', PhrReviewStatus::PENDING);
        $url = "/api/v1/patients/{$patient->id}/episodes/{$episode->id}/encounters";

        $this->postJson($url, ['office_visit_id' => $elsewhere->id])->assertNotFound();
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['office_visit_id' => $visit->id])->assertOk()->assertJsonPath('outcome', 'linked');

        $episode->forceFill(['review_status' => PhrReviewStatus::REJECTED])->save();
        $other = $this->visit($patient, '2025-11-03', PhrReviewStatus::PENDING);
        $this->postJson($url, ['office_visit_id' => $other->id])->assertConflict();
        $this->deleteJson("{$url}/{$visit->id}")->assertOk()->assertJsonPath('outcome', 'unlinked');
    }

    public function test_a_confirmed_episode_exports_as_episode_of_care_with_both_encounters_referencing_it(): void
    {
        $patient = $this->patient();
        $episode = $this->episode($patient, PhrReviewStatus::CONFIRMED);
        $earlier = $this->visit($patient, '2025-11-03', PhrReviewStatus::CONFIRMED, ['visit_ended_at' => '2025-11-06 15:00:00', 'facility_name' => 'Other Community Hospital', 'chief_complaint' => 'Pneumonia']);
        $current = $this->visit($patient, '2026-02-09', PhrReviewStatus::CONFIRMED, ['encounter_state' => 'in-progress']);
        $links = app(PhrEpisodeLinks::class);
        $links->link((int) $patient->id, (int) $episode->id, (int) $earlier->id);
        $links->link((int) $patient->id, (int) $episode->id, (int) $current->id);

        $bundle = $this->fhirExport($patient);
        $resource = $this->resource($bundle, 'EpisodeOfCare', 'phr-episode-'.$episode->id);
        $this->assertNotNull($resource);
        $this->assertSame('active', $resource['status']);
        $this->assertSame(['reference' => 'Patient/phr-patient-'.$patient->id], $resource['patient']);
        $this->assertSame(['start' => '2025-11-03'], $resource['period']);
        $this->assertSame([['text' => 'Illness', 'coding' => [['system' => 'urn:phr:episode-kind', 'code' => 'illness', 'display' => 'Illness']]]], $resource['type']);
        $this->assertStringContainsString('Community-acquired pneumonia', $resource['text']['div']);
        $this->assertArrayNotHasKey('diagnosis', $resource);
        $this->assertArrayNotHasKey('managingOrganization', $resource);

        $reference = [['reference' => 'EpisodeOfCare/phr-episode-'.$episode->id]];
        $this->assertSame($reference, $this->resource($bundle, 'Encounter', 'phr-visit-'.$earlier->id)['episodeOfCare'] ?? null);
        $this->assertSame($reference, $this->resource($bundle, 'Encounter', 'phr-visit-'.$current->id)['episodeOfCare'] ?? null);

        // An episode that ended says finished without a stored status.
        $episode->forceFill(['ended_at' => '2026-02-20 00:00:00'])->save();
        $ended = $this->resource($this->fhirExport($patient), 'EpisodeOfCare', 'phr-episode-'.$episode->id);
        $this->assertSame('finished', $ended['status']);
        $this->assertSame(['start' => '2025-11-03', 'end' => '2026-02-20'], $ended['period']);
    }

    public function test_an_unconfirmed_or_withdrawn_episode_is_not_exported_and_no_reference_dangles(): void
    {
        $patient = $this->patient();
        $episode = $this->episode($patient, PhrReviewStatus::PENDING);
        $earlier = $this->visit($patient, '2025-11-03', PhrReviewStatus::CONFIRMED);
        $current = $this->visit($patient, '2026-02-09', PhrReviewStatus::PENDING);
        $links = app(PhrEpisodeLinks::class);
        $links->link((int) $patient->id, (int) $episode->id, (int) $earlier->id);
        $links->link((int) $patient->id, (int) $episode->id, (int) $current->id);

        $pending = $this->fhirExport($patient);
        $this->assertSame([], $this->resourcesOfType($pending, 'EpisodeOfCare'), 'a pending episode is not exported');
        $this->assertArrayNotHasKey('episodeOfCare', $this->resource($pending, 'Encounter', 'phr-visit-'.$earlier->id));

        // Confirmed, it is exported; the pending visit is not, so nothing references it.
        $episode->forceFill(['review_status' => PhrReviewStatus::CONFIRMED])->save();
        $confirmed = $this->fhirExport($patient);
        $this->assertCount(1, $this->resourcesOfType($confirmed, 'EpisodeOfCare'));
        $this->assertNull($this->resource($confirmed, 'Encounter', 'phr-visit-'.$current->id));
        $this->assertSame([['reference' => 'EpisodeOfCare/phr-episode-'.$episode->id]], $this->resource($confirmed, 'Encounter', 'phr-visit-'.$earlier->id)['episodeOfCare']);

        $episode->forceFill(['retracted_at' => now()])->save();
        $withdrawn = $this->fhirExport($patient);
        $this->assertSame([], $this->resourcesOfType($withdrawn, 'EpisodeOfCare'));
        $this->assertArrayNotHasKey('episodeOfCare', $this->resource($withdrawn, 'Encounter', 'phr-visit-'.$earlier->id));
    }

    public function test_a_pending_episode_waits_on_the_review_page_and_the_browser_reads_it_with_its_visits(): void
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $episode = $this->episode($patient, PhrReviewStatus::PENDING);
        $earlier = $this->visit($patient, '2025-11-03', PhrReviewStatus::CONFIRMED);
        $current = $this->visit($patient, '2026-02-09', PhrReviewStatus::CONFIRMED);
        $unrelated = $this->visit($patient, '2024-05-01', PhrReviewStatus::CONFIRMED);
        $links = app(PhrEpisodeLinks::class);
        $links->link((int) $patient->id, (int) $episode->id, (int) $current->id);
        $links->link((int) $patient->id, (int) $episode->id, (int) $earlier->id);
        $this->actingAs($owner);
        $base = "/api/phr/patients/{$patient->id}";

        $summary = $this->getJson("{$base}/review/summary")->assertOk();
        $this->assertSame(1, $summary->json('summary.total'));
        $this->assertSame(1, $summary->json('summary.sections.office-visits.pending'));
        $items = collect($this->getJson("{$base}/review")->assertOk()->json('groups'))->flatMap(static fn (array $group): array => $group['items']);
        $item = $items->firstWhere('key', 'record:episodes:'.$episode->id);
        $this->assertNotNull($item);
        $this->assertSame('office-visits', $item['section']);
        $this->assertSame('episodes', $item['review_segment']);
        $this->assertSame("/phr/patient/{$patient->id}/office-visits/episode/{$episode->id}", $item['open_path']);
        $this->assertSame('Community-acquired pneumonia', $item['title']);

        $this->patchJson("{$base}/episodes/{$episode->id}/review", ['review_status' => PhrReviewStatus::CONFIRMED, 'version' => $item['version']])
            ->assertOk()->assertJsonPath('episode.review_status', PhrReviewStatus::CONFIRMED);
        $this->assertSame(0, $this->getJson("{$base}/review/summary")->json('summary.total'));

        $shown = $this->getJson("{$base}/episodes/{$episode->id}")->assertOk();
        $this->assertSame([$earlier->id, $current->id], array_column($shown->json('encounters'), 'id'));
        $this->assertSame('Other Community Hospital', $shown->json('encounters.0.facility_name'));
        $this->assertSame([$episode->id], array_column($this->getJson("{$base}/episodes?office_visit_id={$current->id}")->assertOk()->json('episodes'), 'id'));
        $this->assertSame([], $this->getJson("{$base}/episodes?office_visit_id={$unrelated->id}")->assertOk()->json('episodes'));
        $this->assertSame([$earlier->id, $current->id], array_column($this->getJson("{$base}/episodes")->json('episodes.0.encounters'), 'id'));
    }

    /** @return array{0: int, 1: int} the earlier and the current stay */
    private function upsertStays(PhrPatient $patient): array
    {
        $ids = [];
        foreach ([
            'synthetic-stay-earlier' => ['visit_date' => '2025-11-03', 'visit_started_at' => '2025-11-03T15:00:00Z', 'visit_ended_at' => '2025-11-06T17:00:00Z', 'facility_name' => 'Other Community Hospital', 'chief_complaint' => 'Pneumonia', 'encounter_class' => 'IMP', 'encounter_state' => 'finished'],
            'synthetic-stay-current' => ['visit_date' => '2026-02-09', 'visit_started_at' => '2026-02-09T22:20:00Z', 'facility_name' => 'Example General Hospital', 'location' => '6 North Telemetry', 'encounter_class' => 'IMP', 'encounter_state' => 'in-progress'],
        ] as $externalId => $data) {
            $result = $this->tool('office_visits.upsert', ['patient_id' => $patient->id, 'external_id' => $externalId, 'source_document_id' => null, 'expected_version' => null, 'data' => $data]);
            $this->assertFalse($result['isError'] ?? false, json_encode($result));
            $ids[] = (int) $result['structuredContent']['data']['id'];
        }

        return [$ids[0], $ids[1]];
    }

    /** @return array<string, mixed> */
    private function episodeArguments(PhrPatient $patient): array
    {
        return [
            'patient_id' => $patient->id,
            'external_id' => 'synthetic-episode-pneumonia',
            'source_document_id' => null,
            'expected_version' => null,
            'data' => [
                'title' => 'Community-acquired pneumonia',
                'kind' => 'illness',
                'status' => 'active',
                'started_at' => '2025-11-03T00:00:00Z',
                'reason' => 'The earlier pneumonia admission led to the current stay.',
            ],
        ];
    }

    private function episode(PhrPatient $patient, string $review): PhrEpisode
    {
        return PhrEpisode::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'title' => 'Community-acquired pneumonia',
            'kind' => 'illness',
            'started_at' => '2025-11-03 00:00:00',
            'reason' => 'The earlier pneumonia admission led to the current stay.',
            'review_status' => $review,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function visit(PhrPatient $patient, string $date, string $review, array $attributes = []): PhrOfficeVisit
    {
        return PhrOfficeVisit::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'visit_date' => $date,
            'visit_type' => 'Synthetic hospital admission',
            'facility_name' => $date === '2026-02-09' ? 'Example General Hospital' : 'Other Community Hospital',
            'encounter_class' => 'IMP',
            'review_status' => $review,
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function fhirExport(PhrPatient $patient): array
    {
        return json_decode(app(PhrFhirExporter::class)->bundleJson(app(PhrExportDataService::class)->load($patient)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>|null
     */
    private function resource(array $bundle, string $type, string $id): ?array
    {
        foreach ($bundle['entry'] as $entry) {
            if ($entry['resource']['resourceType'] === $type && $entry['resource']['id'] === $id) {
                return $entry['resource'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return list<array<string, mixed>>
     */
    private function resourcesOfType(array $bundle, string $type): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['resource'],
            array_filter($bundle['entry'], static fn (array $entry): bool => $entry['resource']['resourceType'] === $type),
        ));
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function mcpSession(): array
    {
        [$owner, $patient] = $this->restSession([AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $init = $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $this->session = (string) $init->headers->get('Mcp-Session-Id');

        return [$owner, $patient];
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: User, 1: PhrPatient}
     */
    private function restSession(array $scopes): array
    {
        $patient = $this->patient();
        $owner = User::query()->findOrFail($patient->owner_user_id);
        $client = Client::query()->create([
            'name' => 'Synthetic Episode Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($owner, $scopes, 'api', $client);

        return [$owner, $patient];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(string $tool, array $arguments): array
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->nextId, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);

        return $response->json('result') ?? $this->fail('No result: '.$response->getContent());
    }

    /** @param  array<string, mixed>  $message */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers)->assertOk();
    }

    private function patient(string $name = 'Pat Example'): PhrPatient
    {
        $owner = $this->createUser();
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => $name,
            'relationship' => 'self',
            'birth_date' => '1950-06-15',
        ]);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);
        Carbon::setTestNow('2026-03-01 12:00:00');

        return $patient;
    }
}
