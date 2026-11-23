<?php

namespace Tests\Feature;

use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpToolsets;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\GenAiLaravel\Mcp\GenAiMcpToolCatalog;
use Bherila\GenAiLaravel\Mcp\Tools\GenAiMcpTools;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * A session lists the core tools and the toolsets it enabled. Listing is
 * about context size only: scopes decide what exists, and an authorized tool
 * answers a call by name whether or not it is listed.
 */
final class AgentMcpToolsetsTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const array STREAM_ACCEPT = ['Accept' => 'application/json, text/event-stream'];

    private User $actor;

    private Client $client;

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
        Cache::flush();
        $this->actor = User::factory()->create(['user_role' => 'user']);
        $this->client = Client::query()->create([
            'name' => 'Synthetic Toolset Client', 'secret' => null, 'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'], 'grant_types' => ['authorization_code', 'refresh_token'], 'revoked' => false,
        ]);
    }

    public function test_every_tool_belongs_to_exactly_one_toolset_or_the_core(): void
    {
        $names = self::allToolNames();
        $this->assertSame(array_values(array_unique($names)), $names);

        $members = array_fill_keys(array_keys(AgentMcpToolsets::TOOLSETS), []);
        $core = [];
        foreach ($names as $name) {
            // toolsetFor is one function of the name: a tool cannot land in two
            // toolsets, and a tool no rule covers throws instead of hiding.
            $toolset = AgentMcpToolsets::toolsetFor($name);
            if ($toolset === null) {
                $core[] = $name;

                continue;
            }
            $this->assertArrayHasKey($toolset, $members, "{$name} names an undeclared toolset.");
            $members[$toolset][] = $name;
        }

        $meta = [AgentMcpToolsets::LIST, AgentMcpToolsets::ENABLE, AgentMcpToolsets::DISABLE];
        $this->assertEqualsCanonicalizing(array_values(array_diff(AgentMcpToolsets::CORE, $meta)), $core, 'Every core entry is a real tool.');
        foreach ($members as $toolset => $tools) {
            $this->assertNotSame([], $tools, "Toolset {$toolset} holds no tool.");
        }
        $this->assertContains('documents.upload_access_create', $members['documents']);
        $this->assertContains('ndc.lookup', $members['medications']);
        $this->assertContains('office_visits.get_stay', $members['encounters']);
        $this->assertContains('allergies.upsert', $members['clinical_writes']);
        $this->assertContains('claim_genai_request', $members['genai']);
        $this->assertContains('reconciliations.apply', $members['eobs']);
    }

    public function test_the_rule_places_tools_on_known_resources_and_refuses_unknown_ones(): void
    {
        foreach ([
            'clinical.delete' => 'clinical_writes',
            'documents.delete' => 'documents',
            'eobs.delete' => 'eobs',
            'health_logs.update' => 'health_logs',
            'health_log_entries.delete' => 'health_logs',
            'respiratory_events.delete' => 'respiratory',
            'patients.update' => 'patients',
            'vitals.delete' => 'clinical_writes',
        ] as $tool => $toolset) {
            $this->assertSame($toolset, AgentMcpToolsets::toolsetFor($tool), $tool);
        }

        $this->expectException(\LogicException::class);
        AgentMcpToolsets::toolsetFor('billing.list');
    }

    public function test_a_fresh_session_lists_only_the_core_and_announces_list_changes(): void
    {
        $this->actingAsScopes(AgentApiScopes::ids());
        $initialized = $this->initialize();

        $this->assertTrue($initialized->json('result.capabilities.tools.listChanged'));
        $instructions = (string) $initialized->json('result.instructions');
        $this->assertStringContainsString('toolsets.enable', $instructions);
        $this->assertStringContainsString('Enabling grants no access', $instructions);

        $session = (string) $initialized->headers->get('Mcp-Session-Id');
        $listed = $this->listedNames($session);
        $this->assertEqualsCanonicalizing(AgentMcpToolsets::CORE, $listed);
        $this->assertLessThan(count($this->listedNames($session, '/api/v1/mcp?toolsets=all')) / 4, count($listed));

        $tools = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'tools/list', 'params' => new \stdClass], [
            'Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session,
        ])->json('result.tools'), null, 'name');
        foreach ([AgentMcpToolsets::LIST, AgentMcpToolsets::ENABLE, AgentMcpToolsets::DISABLE] as $name) {
            $this->assertTrue($tools[$name]['annotations']['readOnlyHint'], $name);
            $this->assertFalse($tools[$name]['outputSchema']['additionalProperties'], $name);
        }
    }

    public function test_toolsets_list_offers_only_toolsets_the_scopes_allow(): void
    {
        $this->actingAsScopes([AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $session = $this->openSession();

        $toolsets = $this->callTool($session, AgentMcpToolsets::LIST)->json('result.structuredContent.toolsets');
        $byName = array_column($toolsets, null, 'name');
        $this->assertArrayHasKey('documents', $byName);
        $this->assertArrayNotHasKey('imports', $byName);
        $this->assertArrayNotHasKey('genai', $byName);
        $this->assertArrayNotHasKey('patients', $byName);
        $this->assertFalse($byName['documents']['enabled']);
        $this->assertSame(AgentMcpToolsets::TOOLSETS['documents'], $byName['documents']['description']);
        $this->assertSame(4, $byName['documents']['tool_count']);

        $refused = $this->callTool($session, AgentMcpToolsets::ENABLE, ['toolsets' => ['imports']])->json('result');
        $this->assertTrue($refused['isError']);
        $this->assertStringContainsString('toolsets.list', $refused['content'][0]['text']);
        $this->assertNotContains('imports.list', $this->listedNames($session));
    }

    public function test_enabling_and_disabling_change_the_listing_and_notify_the_client(): void
    {
        $this->actingAsScopes(AgentApiScopes::ids());
        $session = $this->openSession();

        $enabled = $this->callTool($session, AgentMcpToolsets::ENABLE, ['toolsets' => ['documents']], self::STREAM_ACCEPT);
        $this->assertStringStartsWith('text/event-stream', (string) $enabled->headers->get('Content-Type'));
        $events = self::events($enabled);
        $this->assertCount(2, $events);
        $this->assertSame('notifications/tools/list_changed', $events[0]['method']);
        $result = $events[1]['result']['structuredContent'];
        $this->assertSame(['documents'], $result['enabled_toolsets']);
        $this->assertTrue($result['list_changed']);
        $this->assertTrue($result['notified']);
        $documentTools = array_column($result['tools'], 'name');
        $this->assertContains('documents.upload_access_create', $documentTools);
        foreach ($result['tools'] as $tool) {
            $this->assertSame('documents', $tool['toolset']);
            $this->assertLessThanOrEqual(200, mb_strlen($tool['description']));
        }

        $listed = $this->listedNames($session);
        $this->assertEqualsCanonicalizing([...AgentMcpToolsets::CORE, ...$documentTools], $listed);
        $this->assertTrue(array_column($this->callTool($session, AgentMcpToolsets::LIST)->json('result.structuredContent.toolsets'), 'enabled', 'name')['documents']);

        // Enabling what is enabled changes nothing and sends nothing.
        $again = $this->callTool($session, AgentMcpToolsets::ENABLE, ['toolsets' => ['documents']], self::STREAM_ACCEPT);
        $this->assertStringStartsWith('application/json', (string) $again->headers->get('Content-Type'));
        $this->assertFalse($again->json('result.structuredContent.list_changed'));

        $disabled = $this->callTool($session, AgentMcpToolsets::DISABLE, ['toolsets' => ['documents']], self::STREAM_ACCEPT);
        $events = self::events($disabled);
        $this->assertSame('notifications/tools/list_changed', $events[0]['method']);
        $this->assertSame([], $events[1]['result']['structuredContent']['enabled_toolsets']);
        $this->assertEqualsCanonicalizing(AgentMcpToolsets::CORE, $this->listedNames($session));
    }

    public function test_a_json_only_client_learns_of_the_change_from_the_result(): void
    {
        $this->actingAsScopes(AgentApiScopes::ids());
        $session = $this->openSession();

        $enabled = $this->callTool($session, AgentMcpToolsets::ENABLE, ['toolsets' => ['imports', 'medications']]);
        $this->assertStringStartsWith('application/json', (string) $enabled->headers->get('Content-Type'));
        $result = $enabled->json('result.structuredContent');
        $this->assertSame(['medications', 'imports'], $result['enabled_toolsets']);
        $this->assertTrue($result['list_changed']);
        $this->assertFalse($result['notified']);
        $this->assertContains('imports.review', $this->listedNames($session));
        $this->assertContains('ndc.lookup', $this->listedNames($session));
    }

    public function test_enabled_toolsets_belong_to_one_session(): void
    {
        $this->actingAsScopes(AgentApiScopes::ids());
        $first = $this->openSession();
        $second = $this->openSession();
        $this->callTool($first, AgentMcpToolsets::ENABLE, ['toolsets' => ['documents']]);

        $this->assertContains('documents.list', $this->listedNames($first));
        $this->assertNotContains('documents.list', $this->listedNames($second));

        // Another credential cannot reach the first session at all.
        Auth::forgetGuards();
        $this->actingAsScopes(AgentApiScopes::ids(), User::factory()->create(['user_role' => 'user']));
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'tools/list', 'params' => new \stdClass], [
            'Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $first,
        ])->assertNotFound();
    }

    public function test_an_unlisted_authorized_tool_can_be_called_and_an_unauthorized_one_cannot(): void
    {
        $patient = PhrPatient::query()->create(['owner_user_id' => $this->actor->id, 'display_name' => 'Synthetic Toolset Patient']);
        PhrPatientUserAccess::query()->create([
            'patient_id' => $patient->id, 'user_id' => $this->actor->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER,
            'granted_by_user_id' => $this->actor->id, 'granted_at' => now(),
        ]);
        $this->actingAsScopes([AgentApiScopes::MCP_USE, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ]);
        $session = $this->openSession();
        $this->assertNotContains('documents.list', $this->listedNames($session));

        $listed = $this->callTool($session, 'documents.list', ['patient_id' => $patient->id])->json('result');
        $this->assertFalse($listed['isError'] ?? false);
        $this->assertSame([], $listed['structuredContent']['data']);

        Auth::forgetGuards();
        $this->actingAsScopes([AgentApiScopes::MCP_USE, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ]);
        $denied = $this->callTool($this->openSession(), 'documents.list', ['patient_id' => $patient->id]);
        $this->assertSame(-32602, $denied->json('error.code'));
        $this->assertNull($denied->json('result'));
    }

    public function test_the_opt_out_lists_every_authorized_tool(): void
    {
        $this->actingAsScopes(AgentApiScopes::ids());
        $initialized = $this->initialize('/api/v1/mcp?toolsets=all');
        $this->assertArrayNotHasKey('listChanged', (array) $initialized->json('result.capabilities.tools'));
        $this->assertStringNotContainsString('toolsets.enable', (string) $initialized->json('result.instructions'));
        $session = (string) $initialized->headers->get('Mcp-Session-Id');

        $everything = $this->listedNames($session, '/api/v1/mcp?toolsets=all');
        $this->assertEqualsCanonicalizing(self::allToolNames(), $everything);
        $this->assertNotContains(AgentMcpToolsets::ENABLE, $everything);
        foreach (AgentMcpToolsets::CORE as $tool) {
            if (! str_starts_with($tool, 'toolsets.')) {
                $this->assertContains($tool, $everything);
            }
        }

        // The deployment-wide setting does the same for every connection.
        config(['agent_api.mcp_tool_listing' => AgentMcpToolsets::ALL]);
        $this->assertSame($everything, $this->listedNames($this->openSession()));
    }

    public function test_a_connection_with_a_single_toolset_lists_it_without_toolsets(): void
    {
        $this->actingAsScopes([AgentApiScopes::MCP_USE, AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK]);
        $initialized = $this->initialize();
        $this->assertArrayNotHasKey('listChanged', (array) $initialized->json('result.capabilities.tools'));

        $this->assertEqualsCanonicalizing(
            ['capabilities.get', 'genai_queue_status', 'claim_genai_request', 'renew_genai_lease', 'complete_genai_request', 'fail_genai_request'],
            $this->listedNames((string) $initialized->headers->get('Mcp-Session-Id')),
        );
    }

    public function test_listing_pages_with_cursors_over_the_listed_tools(): void
    {
        $this->actingAsScopes(AgentApiScopes::ids());
        $session = $this->openSession();
        $this->callTool($session, AgentMcpToolsets::ENABLE, ['toolsets' => array_keys(AgentMcpToolsets::TOOLSETS)]);

        $listed = $this->listedNames($session);
        $this->assertEqualsCanonicalizing([...self::allToolNames(), AgentMcpToolsets::LIST, AgentMcpToolsets::ENABLE, AgentMcpToolsets::DISABLE], $listed);
        $this->assertSame(array_values(array_unique($listed)), $listed);

        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'tools/list', 'params' => ['cursor' => 'not-a-cursor']], [
            'Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session,
        ])->assertOk()->assertJsonPath('error.code', -32602);
    }

    /** @return list<string> every PHR and GenAI tool, as a full-scope connection may call them */
    private static function allToolNames(): array
    {
        return array_map(static fn (ToolDefinition $definition): string => $definition->name, [
            ...app(AgentMcpToolCatalog::class)->definitions(app(AgentMcpReadTools::class), app(AgentMcpWriteTools::class)),
            ...app(GenAiMcpToolCatalog::class)->definitions(app(GenAiMcpTools::class)),
        ]);
    }

    /** @param list<string> $scopes */
    private function actingAsScopes(array $scopes, ?User $user = null): void
    {
        Passport::actingAs($user ?? $this->actor, $scopes, 'api', $this->client);
    }

    /** @return TestResponse<Response> */
    private function initialize(string $path = '/api/v1/mcp'): TestResponse
    {
        return $this->postJson($path, [
            'jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic Toolset Client', 'version' => '1.0.0']],
        ], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk();
    }

    private function openSession(): string
    {
        return (string) $this->initialize()->headers->get('Mcp-Session-Id');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function callTool(string $session, string $tool, array $arguments = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass : $arguments],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session, ...$headers])->assertOk();
    }

    /** @return list<string> */
    private function listedNames(string $session, string $path = '/api/v1/mcp'): array
    {
        $names = [];
        $cursor = null;
        do {
            $page = $this->postJson($path, [
                'jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => 'tools/list',
                'params' => $cursor === null ? new \stdClass : ['cursor' => $cursor],
            ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])->assertOk()->json('result');
            $names = [...$names, ...array_column($page['tools'], 'name')];
            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor !== null);

        return $names;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>> the JSON-RPC messages of an event-stream response
     */
    private static function events(TestResponse $response): array
    {
        preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

        return array_map(static fn (string $data): array => json_decode($data, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    }
}
