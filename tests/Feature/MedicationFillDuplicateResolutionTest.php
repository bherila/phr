<?php

namespace Tests\Feature;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrEobLine;
use App\Models\PhrMedicationFill;
use App\Models\PhrMedicationFillEvidence;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Fills\MedicationFillEvidenceService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/**
 * A fill flagged possible_duplicate appears in the Review queue next to the fill it may
 * repeat, and a person (or their agent, over REST or MCP) answers: the same fill, merged
 * into the existing one and retracted, or a different fill, with the flag cleared.
 */
final class MedicationFillDuplicateResolutionTest extends TestCase
{
    use ConfiguresPassportKeys;
    use FakesOpenFda;
    use RefreshDatabase;

    private User $actor;

    private PhrPatient $patient;

    private Client $client;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        Queue::fake();
        $this->fakeOpenFda();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Duplicate Patient', 'relationship' => 'self']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->client = Client::query()->create([
            'name' => 'Synthetic Receipts', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }

    public function test_the_queue_lists_a_flagged_fill_unchecked_beside_the_fill_it_may_repeat(): void
    {
        [$existing, $flagged] = $this->flaggedPair();
        $this->eobLineFor($flagged);

        $queue = $this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json();
        $item = collect($queue['groups'])->pluck('items')->flatten(1)->firstWhere('id', $flagged->id);

        $this->assertSame('possible_duplicate', $item['flags'][0]['code']);
        $this->assertSame($this->version($flagged), $item['version']);
        $this->assertSame($flagged->id, $item['duplicate']['fill']['id']);
        $candidate = $item['duplicate']['candidates'][0];
        $this->assertSame($existing->id, $candidate['id']);
        $this->assertSame(['id', 'filled_on', 'name', 'strength', 'quantity', 'pharmacy', 'rx_number', 'sources', 'review_status'], array_keys($candidate));
        $this->assertSame('2026-10-28', $candidate['filled_on']);
        $this->assertSame('Example Pharmacy', $item['duplicate']['fill']['pharmacy']);
        $this->assertNull($candidate['pharmacy']);
        $this->assertNotEmpty($candidate['sources']);
        $this->assertContains('EOB line', $item['duplicate']['fill']['sources'], 'evidence beyond the first document is listed');
        $this->assertSame(2, $queue['summary']['sections']['medication-fills']['pending'], 'both fills wait for review');
    }

    public function test_same_fill_moves_the_evidence_retracts_the_duplicate_and_is_idempotent(): void
    {
        [$existing, $flagged, $receipt] = $this->flaggedPair();
        $eobLine = $this->eobLineFor($flagged);
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate";
        $version = $this->version($flagged);

        // A stale version is refused and changes nothing.
        $this->actingAs($this->actor)->postJson($url, ['action' => 'same', 'version' => str_repeat('0', 64)])->assertConflict();
        $this->assertNull($flagged->refresh()->retracted_at);

        $merged = $this->actingAs($this->actor)->postJson($url, ['action' => 'same', 'version' => $version])
            ->assertOk()
            ->assertJsonPath('outcome', 'merged')
            ->assertJsonPath('merged_into_fill_id', $existing->id)
            ->json();
        $this->assertSame(1, $merged['moved']['eob_lines']);

        $flagged->refresh();
        $this->assertNotNull($flagged->retracted_at, 'retracted, not deleted');
        $this->assertNull($flagged->deleted_at);
        $this->assertSame($flagged->id, PhrMedicationFill::query()->withoutGlobalScopes()->findOrFail($flagged->id)->id);
        $held = PhrMedicationFillEvidence::query()->where('fill_id', $existing->id)->get();
        $this->assertContains($eobLine->id, $held->pluck('eob_line_id')->filter()->all(), 'EOB line now vouches for the existing fill');
        $this->assertContains($receipt->id, $held->pluck('document_id')->filter()->all(), 'the flagged fill\'s document is evidence for the existing fill');
        $this->assertSame(PhrMedicationFillEvidence::query()->where('fill_id', $flagged->id)->where('source_kind', 'duplicate_merged')->count(), 1);
        // The pharmacy the existing fill lacked came from the flagged one; it is looked at again.
        $this->assertSame('Example Pharmacy', $existing->refresh()->pharmacy_name);

        // Gone from the queue; the existing fill's own reads show the new sources.
        $queue = $this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/review")->json('groups');
        $this->assertNull(collect($queue)->pluck('items')->flatten(1)->firstWhere('id', $flagged->id));

        // The same decision again, with the version it returned, changes nothing.
        $this->actingAs($this->actor)->postJson($url, ['action' => 'same', 'version' => $merged['medication_fill']['version']])
            ->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('merged_into_fill_id', $existing->id);
        $this->assertSame($held->count(), PhrMedicationFillEvidence::query()->where('fill_id', $existing->id)->count());
    }

    public function test_different_fill_clears_the_flag_and_keeps_both(): void
    {
        [$existing, $flagged] = $this->flaggedPair();
        $url = "/api/phr/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate";

        $this->actingAs($this->actor)->postJson($url, ['action' => 'different', 'version' => str_repeat('1', 64)])->assertConflict();
        $cleared = $this->actingAs($this->actor)->postJson($url, ['action' => 'different', 'version' => $this->version($flagged)])
            ->assertOk()->assertJsonPath('outcome', 'cleared')->assertJsonPath('medication_fill.review_flags', [])->json();

        $this->assertNull($flagged->refresh()->retracted_at);
        $this->assertNull($existing->refresh()->retracted_at);
        $this->assertSame(PhrReviewStatus::PENDING, $flagged->review_status, 'still waits for an ordinary approval');
        $this->actingAs($this->actor)->postJson($url, ['action' => 'different', 'version' => $cleared['medication_fill']['version']])
            ->assertOk()->assertJsonPath('outcome', 'unchanged');

        $item = collect($this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/review")->json('groups'))
            ->pluck('items')->flatten(1)->firstWhere('id', $flagged->id);
        $this->assertSame([], $item['flags']);
        $this->assertArrayNotHasKey('duplicate', $item);
        // A fill without a flag cannot be merged.
        $this->actingAs($this->actor)->postJson($url, ['action' => 'same', 'version' => $cleared['medication_fill']['version']])->assertConflict();
    }

    public function test_a_viewer_cannot_resolve(): void
    {
        [, $flagged] = $this->flaggedPair();
        $viewer = User::factory()->create(['user_role' => 'user']);
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $this->actor->id, 'granted_at' => now()]);

        $this->actingAs($viewer)->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate", ['action' => 'different', 'version' => $this->version($flagged)])->assertForbidden();
    }

    public function test_a_pending_eob_is_in_the_queue_and_can_be_approved_or_rejected(): void
    {
        $eob = PhrEob::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'import_source' => 'agent-client:x', 'external_id' => 'eob-q1',
            'claim_type' => 'medical', 'provider_name' => 'Example Clinic', 'claim_number' => 'C100', 'review_status' => PhrReviewStatus::PENDING,
        ]);
        PhrEob::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'import_source' => 'console', 'external_id' => 'eob-q2',
            'claim_type' => 'medical', 'review_status' => PhrReviewStatus::CONFIRMED,
        ]);

        $queue = $this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/review")->assertOk()->json();
        $items = collect($queue['groups'])->pluck('items')->flatten(1)->where('review_segment', 'eobs')->values();
        $this->assertCount(1, $items, 'only the pending EOB');
        $this->assertSame($eob->id, $items[0]['id']);
        $this->assertSame('documents', $items[0]['section']);
        $this->assertStringContainsString('Example Clinic', $items[0]['title']);
        $this->assertSame("/phr/patient/{$this->patient->id}/eobs/{$eob->id}", $items[0]['open_path']);
        $this->assertSame(1, $queue['summary']['total']);
        $this->assertSame(1, $queue['summary']['sections']['documents']['pending']);

        $this->actingAs($this->actor)->patchJson("/api/phr/patients/{$this->patient->id}/eobs/{$eob->id}/review", ['review_status' => 'confirmed', 'version' => str_repeat('0', 64)])->assertConflict();
        $this->actingAs($this->actor)->patchJson("/api/phr/patients/{$this->patient->id}/eobs/{$eob->id}/review", ['review_status' => 'confirmed', 'version' => $items[0]['version']])->assertOk();
        $this->assertSame(0, $this->actingAs($this->actor)->getJson("/api/phr/patients/{$this->patient->id}/review/summary")->json('summary.total'));
    }

    public function test_the_agent_operation_resolves_over_rest_and_mcp_with_the_same_contract(): void
    {
        [$existing, $flagged] = $this->flaggedPair();
        [$otherExisting, $otherFlagged] = $this->flaggedPair('2026-09-10');
        $this->asClient();
        $url = "/api/v1/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate";

        $this->postJson($url, ['action' => 'same', 'expected_version' => str_repeat('0', 64)])->assertConflict();
        $this->postJson($url, ['action' => 'maybe', 'expected_version' => $this->version($flagged)])->assertUnprocessable();
        $rest = $this->postJson($url, ['action' => 'same', 'expected_version' => $this->version($flagged)])
            ->assertOk()
            ->assertJsonPath('resource_type', 'medication_fill_duplicate_resolution')
            ->assertJsonPath('outcome', 'merged')
            ->assertJsonPath('data.merged_into_fill_id', $existing->id)
            ->json();
        $this->assertNotNull($flagged->refresh()->retracted_at);
        $this->postJson($url, ['action' => 'same', 'expected_version' => $rest['data']['version']])->assertOk()->assertJsonPath('outcome', 'unchanged');

        // MCP: same arguments, same structured answer.
        $this->session = (string) $this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]])->headers->get('Mcp-Session-Id');
        $tool = collect($this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->json('result.tools'))->firstWhere('name', 'medication_fills.resolve_duplicate');
        $this->assertSame(['patient_id', 'fill_id', 'action', 'expected_version'], $tool['inputSchema']['required']);
        $this->assertSame(['same', 'different'], $tool['inputSchema']['properties']['action']['enum']);

        $result = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'medication_fills.resolve_duplicate', 'arguments' => [
            'patient_id' => $this->patient->id, 'fill_id' => $otherFlagged->id, 'action' => 'different', 'expected_version' => $this->version($otherFlagged),
        ]]])->json('result');
        $this->assertNotTrue($result['isError'] ?? false, json_encode($result));
        $this->assertSame('medication_fill_duplicate_resolution', $result['structuredContent']['resource_type']);
        $this->assertSame('cleared', $result['structuredContent']['outcome']);
        $this->assertSame($otherFlagged->id, $result['structuredContent']['data']['fill_id']);
        $this->assertNull($otherFlagged->refresh()->retracted_at);
        $this->assertSame([], PhrMedicationFillEvidence::query()->where('fill_id', $otherFlagged->id)->where('source_kind', 'possible_duplicate')->get()->all());

        // A stale version over MCP is the same conflict.
        $stale = $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'medication_fills.resolve_duplicate', 'arguments' => [
            'patient_id' => $this->patient->id, 'fill_id' => $otherFlagged->id, 'action' => 'same', 'expected_version' => str_repeat('0', 64),
        ]]])->json('result');
        $this->assertTrue($stale['isError'] ?? false);
        unset($otherExisting);
    }

    public function test_a_retry_of_a_merged_fill_lands_on_the_surviving_fill_and_a_rejected_survivor_refuses_the_merge(): void
    {
        [$existing, $flagged, $receipt] = $this->flaggedPair();
        $this->actingAs($this->actor)->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate", ['action' => 'same', 'version' => $this->version($flagged)])->assertOk();

        $this->asClient();
        $this->write($receipt, ['filled_on' => '2026-10-28', 'ndc' => '58151-0155-77', 'quantity' => 30, 'quantity_unit' => 'tablet', 'rx_number' => null, 'source_kind' => 'receipt_pdf', 'pharmacy_name' => 'Example Pharmacy'])
            ->assertOk()->assertJsonPath('data.id', $existing->id);
        $this->assertSame(2, PhrMedicationFill::query()->withoutGlobalScopes()->count());

        [$survivor, $second] = $this->flaggedPair('2026-08-01');
        $survivor->update(['review_status' => PhrReviewStatus::REJECTED]);
        $this->actingAs($this->actor)->postJson("/api/phr/patients/{$this->patient->id}/medication-fills/{$second->id}/resolve-duplicate", ['action' => 'same', 'version' => $this->version($second)])->assertConflict();
        $this->assertNull($second->refresh()->retracted_at);
    }

    public function test_an_agent_cannot_resolve_a_fill_another_connection_wrote(): void
    {
        [, $flagged] = $this->flaggedPair();
        $other = Client::query()->create([
            'name' => 'Synthetic Other', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        Passport::actingAs($this->actor, [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], 'api', $other);

        $this->postJson("/api/v1/patients/{$this->patient->id}/medication-fills/{$flagged->id}/resolve-duplicate", ['action' => 'same', 'expected_version' => $this->version($flagged)])->assertNotFound();
    }

    // ---- helpers --------------------------------------------------------------------------

    /**
     * An existing fill (label, no pharmacy) and a fill the client wrote from a receipt that
     * the server flagged as possibly the same dispensing.
     *
     * @return array{0: PhrMedicationFill, 1: PhrMedicationFill, 2: PhrDocument}
     */
    private function flaggedPair(string $day = '2026-10-28'): array
    {
        $label = $this->document();
        $receipt = $this->document();
        $this->asClient();
        $base = ['filled_on' => $day, 'ndc' => '58151-0155-77', 'quantity' => 30, 'quantity_unit' => 'tablet', 'rx_number' => null, 'source_kind' => 'label_photo'];
        $existingId = $this->write($label, [...$base, 'pharmacy_name' => null])->assertCreated()->json('data.id');
        $flaggedId = $this->write($receipt, [...$base, 'pharmacy_name' => 'Example Pharmacy', 'source_kind' => 'receipt_pdf'])
            ->assertCreated()->assertJsonPath('dedupe.status', 'flagged')->json('data.id');

        return [PhrMedicationFill::query()->findOrFail($existingId), PhrMedicationFill::query()->findOrFail($flaggedId), $receipt];
    }

    /** @param array<string, mixed> $data */
    private function write(PhrDocument $document, array $data): TestResponse
    {
        return $this->putJson("/api/v1/patients/{$this->patient->id}/medication-fills", [
            'source_document_id' => $document->id, 'expected_version' => null, 'data' => $data,
        ]);
    }

    private function eobLineFor(PhrMedicationFill $fill): PhrEobLine
    {
        $eob = PhrEob::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'import_source' => 'agent-client:x', 'external_id' => 'eob-d1',
            'claim_type' => 'pharmacy', 'review_status' => PhrReviewStatus::PENDING,
        ]);
        $line = PhrEobLine::query()->create(['eob_id' => $eob->id, 'patient_id' => $this->patient->id, 'line_number' => 1, 'procedure_code' => '5815101577']);
        app(MedicationFillEvidenceService::class)->record($this->patient, $fill, null, $line->id, 'eob', null);

        return $line;
    }

    private function version(PhrMedicationFill $fill): string
    {
        return app(AgentClinicalRecordVersion::class)->for($fill->refresh());
    }

    private function asClient(): void
    {
        Passport::actingAs($this->actor, array_values(array_diff(AgentApiScopes::ids(), [AgentApiScopes::BILLING_READ])), 'api', $this->client);
    }

    /** @param array<string, mixed> $message */
    private function mcp(array $message): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if (isset($this->session) && $message['method'] !== 'initialize') {
            $headers['Mcp-Session-Id'] = $this->session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers)->assertOk();
    }

    private function document(): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->actor->id, 'uploaded_by_user_id' => $this->actor->id,
            'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0,
        ]);
    }
}
