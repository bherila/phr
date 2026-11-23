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
use App\Services\PHR\Eob\EobWriteService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/**
 * Deleting an EOB (#444), in the browser and as an agent (eobs.delete): a soft delete whose
 * lines, correction history and links stay stored and stop counting. Every value is synthetic.
 */
final class EobDeletionTest extends TestCase
{
    use ConfiguresPassportKeys;
    use FakesOpenFda;
    use RefreshDatabase;

    private const string ALL_TOOLSETS = '/api/v1/mcp?toolsets=all';

    private const string STALE = '0000000000000000000000000000000000000000000000000000000000000000';

    private const array DELETE_SCOPES = [AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_DELETE];

    private Client $client;

    private User $person;

    private PhrPatient $patient;

    private int $sequence = 0;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Storage::fake(PhrDocument::STORAGE_DISK);
        $this->fakeOpenFda();
        $this->travelTo(Carbon::parse('2026-03-04 05:06:07'));
        $this->client = Client::query()->create([
            'name' => 'Synthetic EOB Deletion Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
        $this->person = $this->createUser();
        $this->patient = $this->patientOf($this->person);
    }

    public function test_an_agent_deletes_an_eob_as_the_browser_does_and_its_links_stop_counting(): void
    {
        $visitId = (int) $this->actingAs($this->person)->postJson($this->browser('office-visits'), ['visit_date' => '2026-01-15'])->assertCreated()->json('office_visit.id');
        $procedureId = (int) $this->actingAs($this->person)->postJson($this->browser('procedures'), ['name' => 'Synthetic procedure'])->assertCreated()->json('procedure.id');
        $eob = $this->claim('medical', 'CLM-1');
        $lineIds = $eob->lines()->orderBy('line_number')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $this->actingAs($this->person)->postJson($this->browser("office-visits/{$visitId}/eobs/{$eob->id}"))->assertCreated();
        $this->actingAs($this->person)->postJson($this->browser("procedures/{$procedureId}/eobs/{$eob->id}"))->assertCreated();
        // A fill a person linked to one of its lines.
        $fill = PhrMedicationFill::factory()->create(['patient_id' => $this->patient->id, 'user_id' => $this->person->id, 'import_source' => 'synthetic', 'external_id' => 'fill-1']);
        PhrMedicationFillEvidence::query()->create(['patient_id' => $this->patient->id, 'fill_id' => $fill->id, 'eob_line_id' => $lineIds[0], 'source_kind' => 'eob', 'evidence_key' => str_repeat('a', 40)]);
        // A correction, so the claim has history.
        $this->agent([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE]);
        $version = (string) $this->getJson($this->agentUrl("eobs/{$eob->id}"))->assertOk()->json('data.version');
        $this->patchJson($this->agentUrl("eobs/{$eob->id}"), ['expected_version' => $version, 'data' => ['provider_name' => 'Corrected Provider']])->assertOk()->assertJsonPath('outcome', 'updated');
        $this->assertSame(1, DB::table('phr_eob_corrections')->where('eob_id', $eob->id)->count());
        $this->agent(self::DELETE_SCOPES);
        $this->assertSame([$lineIds[0]], $this->getJson($this->agentUrl("medication-fills/{$fill->id}"))->assertOk()->json('data.eob_line_ids'));

        $this->travel(1)->minutes();
        $url = $this->agentUrl("eobs/{$eob->id}");
        $version = (string) $this->getJson($url)->assertOk()->json('data.version');
        $deleted = $this->assertDeletesAsTheBrowserDoes($this->browser("eobs/{$eob->id}"), $url, $version);

        $tombstone = ['resource_type' => 'eobs', 'id' => $eob->id, 'patient_id' => $this->patient->id, 'change_type' => 'deleted', 'changed_at' => now()->toIso8601String()];
        $this->assertSame('eob', $deleted['resource_type']);
        $this->assertSame($tombstone, $deleted['data']);
        $this->assertContains($tombstone, $this->getJson($this->agentUrl('changes').'?'.http_build_query(['resource_type' => ['eobs']]))->assertOk()->json('data'));

        // Gone from every read: the claim, its lines, the lists.
        $this->getJson($url)->assertNotFound();
        $this->getJson("{$url}/lines")->assertNotFound();
        $this->getJson("{$url}/lines/{$lineIds[0]}")->assertNotFound();
        $this->assertSame([], $this->getJson($this->agentUrl('eobs'))->assertOk()->json('data'));
        $this->getJson($this->agentUrl('evidence-links').'?'.http_build_query(['resource_type' => 'eob-line', 'resource_id' => $lineIds[0]]))->assertNotFound();

        // Stored, unchanged: the row, its lines, its history and every link.
        $this->assertNotNull(PhrEob::withTrashed()->findOrFail($eob->id)->deleted_at);
        $this->assertSame(2, DB::table('phr_eob_lines')->where('eob_id', $eob->id)->count());
        $this->assertSame(1, DB::table('phr_eob_corrections')->where('eob_id', $eob->id)->count());
        $this->assertSame(1, DB::table('phr_office_visit_eobs')->where('eob_id', $eob->id)->count());
        $this->assertSame(1, DB::table('phr_procedure_eobs')->where('eob_id', $eob->id)->count());
        $this->assertSame(1, DB::table('phr_medication_fill_evidence')->where('eob_line_id', $lineIds[0])->count());

        // ...and none of them counts any more.
        $fillRead = $this->getJson($this->agentUrl("medication-fills/{$fill->id}"))->assertOk()->json('data');
        $this->assertSame([], $fillRead['eob_line_ids']);
        $this->assertSame([], $fillRead['eob_links']);
        // The fill's links changed, so a client syncing on updated_at sees it.
        $this->assertSame(now()->toDateTimeString(), DB::table('phr_medication_fills')->where('id', $fill->id)->value('updated_at'));
        foreach (['office-visit' => $visitId, 'procedure' => $procedureId, 'medication-fill' => $fill->id] as $type => $id) {
            $links = $this->getJson($this->agentUrl('evidence-links').'?'.http_build_query(['resource_type' => $type, 'resource_id' => $id]))->assertOk()->json('data');
            $this->assertSame([], array_values(array_filter($links, static fn (array $link): bool => in_array($link['target_type'], ['eob', 'eob-line'], true))), $type);
        }
        $this->actingAs($this->person)->getJson($this->browser("office-visits/{$visitId}"))->assertOk()->assertJsonPath('office_visit.eobs', []);
        $this->actingAs($this->person)->getJson($this->browser("procedures/{$procedureId}"))->assertOk()->assertJsonPath('procedure.eobs', []);
        $this->actingAs($this->person)->getJson($this->browser('eobs').'?service_date=2026-01-15')->assertOk()->assertJsonPath('eobs', []);
        $this->actingAs($this->person)->get("/phr/patient/{$this->patient->id}/eobs/{$eob->id}")->assertNotFound();
        // A link to it can no longer be made or reviewed.
        $this->actingAs($this->person)->postJson($this->browser("office-visits/{$visitId}/eobs/{$eob->id}"))->assertNotFound();
        $this->actingAs($this->person)->patchJson($this->browser("eobs/{$eob->id}/review"), ['review_status' => 'confirmed'])->assertNotFound();
    }

    public function test_deleting_a_pharmacy_claim_withdraws_its_automatic_fill_links_and_frees_the_fill(): void
    {
        $fill = PhrMedicationFill::factory()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->person->id, 'review_status' => PhrReviewStatus::PENDING, 'filled_on' => '2026-01-05',
            'ndc_status' => PhrMedicationFill::NDC_MANUAL, 'ndc_as_captured' => null, 'ndc_10' => null, 'ndc_11' => null,
            'drug_as_printed' => 'Synthetic alpha', 'import_source' => 'synthetic', 'external_id' => 'fill-1',
        ]);
        $claim = $this->claim('pharmacy', 'RX-1', '2026-01-05');
        $this->assertSame(2, $this->automaticLinks($fill)->count(), 'the sole pair of the day links');

        $this->agent(self::DELETE_SCOPES);
        $version = (string) $this->getJson($this->agentUrl("eobs/{$claim->id}"))->assertOk()->json('data.version');
        $this->deleteJson($this->agentUrl("eobs/{$claim->id}"), ['expected_version' => $version])->assertOk()->assertJsonPath('outcome', 'deleted');

        // The linker no longer sees the claim, so its own links to it are withdrawn...
        $this->assertSame(0, $this->automaticLinks($fill)->count());
        $this->assertSame([], $this->getJson($this->agentUrl("medication-fills/{$fill->id}"))->assertOk()->json('data.eob_line_ids'));
        // ...and the fill is free for another claim of the day.
        $other = $this->claim('pharmacy', 'RX-2', '2026-01-05');
        $this->assertSame(
            $other->lines()->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            $this->automaticLinks($fill)->pluck('eob_line_id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all(),
        );
    }

    public function test_a_deleted_claim_is_not_written_or_linked_again(): void
    {
        $document = $this->document();
        $payload = [
            'source_document_id' => $document->id,
            'data' => [
                'claim_type' => 'medical', 'claim_number' => 'CLM-100', 'provider_name' => 'Example Provider', 'processed_date' => '2026-11-02',
                'lines' => [['procedure_code' => '99213', 'service_start' => '2026-10-28', 'total_charges' => '100.00', 'plan_payment' => '50.00']],
            ],
        ];
        $this->agent([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::CLINICAL_DELETE]);
        $created = $this->putJson($this->agentUrl('eobs'), $payload)->assertCreated()->json();
        $eobId = (int) $created['data']['id'];
        $lineId = (int) $created['lines'][0]['id'];
        $this->deleteJson($this->agentUrl("eobs/{$eobId}"), ['expected_version' => $created['version']])->assertOk()->assertJsonPath('outcome', 'deleted');

        // The same connection, and another reading the same claim, are both refused.
        $this->putJson($this->agentUrl('eobs'), $payload)->assertConflict()->assertJsonPath('message', EobWriteService::DELETED_CLAIM);
        $this->agent([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE], $this->otherClient());
        $this->putJson($this->agentUrl('eobs'), [...$payload, 'external_id' => 'another-reading'])->assertConflict()->assertJsonPath('message', EobWriteService::DELETED_CLAIM);
        $this->assertSame(1, PhrEob::withTrashed()->count());

        // A fill is not linked to a line of it.
        $this->putJson($this->agentUrl('medication-fills'), [
            'external_id' => 'fill:synthetic:deleted-claim', 'source_document_id' => $document->id, 'expected_version' => null,
            'data' => ['drug_as_printed' => 'Synthetic Drug', 'filled_on' => '2026-10-28', 'quantity' => 30, 'source_kind' => 'receipt_pdf', 'eob_line_id' => $lineId],
        ])->assertUnprocessable()->assertJsonValidationErrors('data.eob_line_id');
    }

    public function test_the_delete_needs_its_own_scope_and_the_tool_is_listed_only_with_it(): void
    {
        $eob = $this->claim('medical', 'CLM-1');
        $url = $this->agentUrl("eobs/{$eob->id}");
        $this->agent([AgentApiScopes::CLINICAL_READ]);
        $version = (string) $this->getJson($url)->assertOk()->json('data.version');

        // Writing EOBs is not deleting them, and the version comes from a read.
        $this->agent([AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::BILLING_READ]);
        $this->deleteJson($url, ['expected_version' => $version])->assertForbidden();
        $this->agent([AgentApiScopes::CLINICAL_DELETE]);
        $this->deleteJson($url, ['expected_version' => $version])->assertForbidden();
        $this->assertNull(PhrEob::withTrashed()->findOrFail($eob->id)->deleted_at);

        $base = [AgentApiScopes::MCP_USE, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE];
        $this->assertArrayNotHasKey('eobs.delete', $this->tools($base));
        $tools = $this->tools([...$base, AgentApiScopes::CLINICAL_DELETE]);
        $this->assertTrue($tools['eobs.delete']['annotations']['destructiveHint']);
        $this->assertSame(['eob_id', 'expected_version', 'patient_id'], $this->sorted($tools['eobs.delete']['inputSchema']['required']));

        $arguments = ['patient_id' => $this->patient->id, 'eob_id' => $eob->id, 'expected_version' => $version];
        $this->assertTrue($this->callTool('eobs.delete', [...$arguments, 'expected_version' => self::STALE])['isError'] ?? false);
        $this->assertSame('deleted', $this->callTool('eobs.delete', $arguments)['structuredContent']['outcome']);
        $this->assertSame('unchanged', $this->callTool('eobs.delete', $arguments)['structuredContent']['outcome']);
    }

    public function test_only_a_person_who_may_change_the_patient_deletes_its_eob(): void
    {
        $eob = $this->claim('medical', 'CLM-1');
        $viewer = $this->createUser();
        PhrPatientUserAccess::query()->create(['patient_id' => $this->patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $this->person->id, 'granted_at' => now()]);
        $this->actingAs($viewer)->deleteJson($this->browser("eobs/{$eob->id}"))->assertForbidden();
        $this->agent(self::DELETE_SCOPES, user: $viewer);
        $version = (string) $this->getJson($this->agentUrl("eobs/{$eob->id}"))->assertOk()->json('data.version');
        $this->deleteJson($this->agentUrl("eobs/{$eob->id}"), ['expected_version' => $version])->assertForbidden();

        // Another patient's EOB is not reached through this one's URL.
        $other = $this->patientOf($this->person);
        $this->actingAs($this->person)->deleteJson("/api/phr/patients/{$other->id}/eobs/{$eob->id}")->assertNotFound();
        $this->assertNull(PhrEob::withTrashed()->findOrFail($eob->id)->deleted_at);

        // The EOB page offers the delete to a person who may make it.
        $this->actingAs($this->person)->get("/phr/patient/{$this->patient->id}/eobs/{$eob->id}")->assertOk()->assertSee('data-can-delete="true"', false);
        $this->actingAs($viewer)->get("/phr/patient/{$this->patient->id}/eobs/{$eob->id}")->assertOk()->assertSee('data-can-delete="false"', false);
        $this->actingAs($this->person)->deleteJson($this->browser("eobs/{$eob->id}"))->assertNoContent();
        $this->actingAs($this->person)->deleteJson($this->browser("eobs/{$eob->id}"))->assertNotFound();
    }

    /**
     * Deletes the EOB in the browser inside a savepoint, rolls that back, deletes it as an
     * agent, and asserts every PHR table ends the same; then the version and the repeat.
     *
     * @return array<string, mixed> the agent's delete response
     */
    private function assertDeletesAsTheBrowserDoes(string $browserUrl, string $agentUrl, string $version): array
    {
        DB::beginTransaction();
        $this->actingAs($this->person)->deleteJson($browserUrl)->assertNoContent();
        $browser = $this->snapshot();
        DB::rollBack();
        $before = $this->snapshot();

        $this->agent(self::DELETE_SCOPES);
        $this->deleteJson($agentUrl, [])->assertUnprocessable();
        $this->deleteJson($agentUrl, ['expected_version' => self::STALE])->assertConflict();
        $this->assertEquals($before, $this->snapshot(), 'A stale version changed nothing.');

        $deleted = $this->deleteJson($agentUrl, ['expected_version' => $version])->assertOk()->assertJsonPath('outcome', 'deleted')->json();
        $this->assertEquals($browser, $this->snapshot(), 'The agent delete left the patient as the browser delete does.');

        $this->deleteJson($agentUrl, ['expected_version' => $version])->assertOk()->assertJsonPath('outcome', 'unchanged')->assertJsonPath('data', $deleted['data']);
        $this->deleteJson($agentUrl, ['expected_version' => self::STALE])->assertOk()->assertJsonPath('outcome', 'unchanged');
        $this->assertEquals($browser, $this->snapshot());

        return $deleted;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (collect(Schema::getTables())->pluck('name')->filter(static fn (string $table): bool => str_starts_with($table, 'phr_'))->unique()->sort() as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)
                ->sortBy(static fn (array $row): string => (string) json_encode($row))->values()->all();
        }

        return $snapshot;
    }

    private function claim(string $type, string $number, string $day = '2026-01-15'): PhrEob
    {
        return DB::transaction(function () use ($type, $number, $day): PhrEob {
            $eob = PhrEob::query()->create([
                'patient_id' => $this->patient->id, 'user_id' => $this->person->id, 'import_source' => 'synthetic', 'external_id' => 'eob-'.(++$this->sequence),
                'claim_number' => $number, 'claim_type' => $type, 'provider_name' => 'Example Provider', 'review_status' => PhrReviewStatus::PENDING,
                'processed_date' => $day, 'total_charges' => '100.00',
            ]);
            foreach ([1 => 'PLN', 2 => 'PAT'] as $lineNumber => $code) {
                PhrEobLine::query()->create([
                    'eob_id' => $eob->id, 'patient_id' => $this->patient->id, 'line_number' => $lineNumber, 'procedure_code' => $code,
                    'code_type' => $type === 'pharmacy' ? 'pharmacy_plan' : 'CPT', 'service_start' => $day, 'service_end' => $day,
                ]);
            }

            return $eob;
        });
    }

    /** @return Collection<int, PhrMedicationFillEvidence> */
    private function automaticLinks(PhrMedicationFill $fill): Collection
    {
        return PhrMedicationFillEvidence::query()->where('fill_id', $fill->id)->where('source_kind', PhrMedicationFillEvidence::KIND_AUTO_EOB_LINK)->get()->toBase();
    }

    private function patientOf(User $owner): PhrPatient
    {
        $patient = PhrPatient::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'Synthetic Patient']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $owner->id, 'granted_at' => now(),
        ]);

        return $patient;
    }

    private function document(): PhrDocument
    {
        return PhrDocument::query()->create([
            'patient_id' => $this->patient->id, 'user_id' => $this->person->id, 'uploaded_by_user_id' => $this->person->id,
            'document_type' => 'other', 'source' => 'manual_upload', 'storage_disk' => PhrDocument::STORAGE_DISK, 'byte_size' => 0,
        ]);
    }

    private function browser(string $path): string
    {
        return "/api/phr/patients/{$this->patient->id}/{$path}";
    }

    private function agentUrl(string $path): string
    {
        return "/api/v1/patients/{$this->patient->id}/{$path}";
    }

    /** @param  list<string>  $scopes */
    private function agent(array $scopes, ?Client $client = null, ?User $user = null): void
    {
        Passport::actingAs($user ?? $this->person, $scopes, 'api', $client ?? $this->client);
    }

    private function otherClient(): Client
    {
        return Client::query()->create([
            'name' => 'Synthetic Second Reader', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://reader.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, array<string, mixed>>
     */
    private function tools(array $scopes): array
    {
        $this->agent($scopes);
        $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->sequence, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1.0.0'],
        ]]);
        $tools = [];
        $cursor = null;
        do {
            $result = $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->sequence, 'method' => 'tools/list', 'params' => $cursor === null ? new \stdClass : ['cursor' => $cursor]])->json('result');
            $tools = [...$tools, ...$result['tools']];
            $cursor = $result['nextCursor'] ?? null;
        } while ($cursor !== null);

        return collect($tools)->keyBy('name')->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        return $this->mcp(['jsonrpc' => '2.0', 'id' => ++$this->sequence, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])->json('result')
            ?? $this->fail('No result for '.$name);
    }

    /** @param  array<string, mixed>  $message */
    private function mcp(array $message): TestResponse
    {
        $response = $this->postJson(self::ALL_TOOLSETS, $message, ['Mcp-Protocol-Version' => '2025-06-18', ...(isset($this->session) && $message['method'] !== 'initialize' ? ['Mcp-Session-Id' => $this->session] : [])])->assertOk();
        if ($message['method'] === 'initialize') {
            $this->session = (string) $response->headers->get('Mcp-Session-Id');
        }

        return $response;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
