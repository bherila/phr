<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * What each kind of caller sees over MCP, pinned: tool names per scope set,
 * every tool's full definition, and the server instructions and prompts.
 *
 * Moving tool exposure onto a different mechanism (the capability registry)
 * must leave this snapshot byte-for-byte unchanged. To accept an intended
 * change, run once with UPDATE_MCP_SNAPSHOT=1 and review the fixture diff.
 */
final class AgentMcpToolSnapshotTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const string FIXTURE = __DIR__.'/../Fixtures/mcp/tool-snapshot.json';

    /** @return array<string, list<string>> */
    private static function principals(): array
    {
        return [
            'connection only' => [AgentApiScopes::MCP_USE],
            'identity and patients' => [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ],
            'clinical reader' => [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ],
            'clinical writer' => [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE],
            'import reviewer' => [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::DOCUMENTS_READ, AgentApiScopes::IMPORTS_READ, AgentApiScopes::IMPORTS_WRITE],
            'write without read' => [AgentApiScopes::MCP_USE, AgentApiScopes::CLINICAL_WRITE, AgentApiScopes::DOCUMENTS_WRITE, AgentApiScopes::EXPORTS_WRITE],
            'genai status' => [AgentApiScopes::MCP_USE, AgentApiScopes::GENAI_READ],
            'genai worker' => [AgentApiScopes::MCP_USE, AgentApiScopes::GENAI_READ, AgentApiScopes::GENAI_WORK],
            'everything' => AgentApiScopes::ids(),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_each_principal_sees_exactly_the_snapshotted_tools(): void
    {
        $actor = User::factory()->create(['name' => 'Synthetic Snapshot User', 'email' => 'snapshot@example.test', 'user_role' => 'user']);
        $client = Client::query()->create([
            'name' => 'Synthetic Snapshot Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);

        $snapshot = ['principals' => [], 'tools' => []];
        foreach (self::principals() as $label => $scopes) {
            Auth::forgetGuards();
            Passport::actingAs($actor, $scopes, 'api', $client);
            [$instructions, $tools, $prompts] = $this->listFor();
            $snapshot['principals'][$label] = [
                'scopes' => $scopes,
                'instructions' => $instructions,
                'prompts' => $prompts,
                'tools' => array_keys($tools),
            ];
            foreach ($tools as $name => $definition) {
                if (isset($snapshot['tools'][$name])) {
                    $this->assertSame($snapshot['tools'][$name], $definition, "Tool {$name} must not vary by principal");
                }
                $snapshot['tools'][$name] = $definition;
            }
        }
        ksort($snapshot['tools']);
        $encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (getenv('UPDATE_MCP_SNAPSHOT') === '1') {
            file_put_contents(self::FIXTURE, $encoded);
            $this->markTestSkipped('MCP tool snapshot rewritten; review the fixture diff.');
        }
        $this->assertFileExists(self::FIXTURE, 'Create the snapshot with UPDATE_MCP_SNAPSHOT=1.');
        $this->assertSame((string) file_get_contents(self::FIXTURE), $encoded);
    }

    /**
     * Metadata verbatim, schemas by digest: a schema change still fails the
     * snapshot while the fixture stays reviewable.
     *
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private static function pin(array $tool): array
    {
        foreach (['inputSchema', 'outputSchema'] as $schema) {
            if (array_key_exists($schema, $tool)) {
                $tool[$schema] = 'sha256:'.hash('sha256', json_encode($tool[$schema], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        }
        ksort($tool);

        return $tool;
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: list<string>} */
    private function listFor(): array
    {
        $initialized = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic Snapshot', 'version' => '1.0.0']],
        ], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk();
        $session = (string) $initialized->headers->get('Mcp-Session-Id');
        $headers = ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session];
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);

        $tools = [];
        $cursor = null;
        $id = 2;
        do {
            $page = $this->postJson('/api/v1/mcp', [
                'jsonrpc' => '2.0', 'id' => $id++, 'method' => 'tools/list',
                'params' => $cursor === null ? new \stdClass : ['cursor' => $cursor],
            ], $headers)->assertOk()->json('result');
            foreach ($page['tools'] as $tool) {
                $tools[$tool['name']] = self::pin($tool);
            }
            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor !== null);
        ksort($tools);

        $prompts = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'prompts/list', 'params' => new \stdClass], $headers)
            ->assertOk()->json('result.prompts') ?? [], 'name');
        sort($prompts);

        return [(string) $initialized->json('result.instructions'), $tools, $prompts];
    }
}
