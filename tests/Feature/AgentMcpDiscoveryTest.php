<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Laravel\Passport\Passport;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

final class AgentMcpDiscoveryTest extends TestCase
{
    use ConfiguresPassportKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePassportKeys();
    }

    /** @return iterable<string, array{?string}> */
    public static function protocolVersions(): iterable
    {
        yield 'no version header' => [null];
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            yield $version->value => [$version->value];
        }
        yield 'unsupported version' => ['1900-01-01'];
        yield 'modern version without initialize' => ['2026-07-28'];
    }

    #[DataProvider('protocolVersions')]
    public function test_initialize_and_discovery_agree_on_protocol_versions(?string $version): void
    {
        Passport::actingAs(User::factory()->create(['user_role' => 'user']), [AgentApiScopes::MCP_USE, AgentApiScopes::PATIENTS_READ]);
        $supported = array_map(static fn (ProtocolVersion $version): string => $version->value, ProtocolVersion::handshakeVersions());
        $headers = $version === null ? [] : ['Mcp-Protocol-Version' => $version];
        $initialized = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::latestHandshake()->value,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'Synthetic discovery client', 'version' => '1'],
            ],
        ], $headers);
        $discovered = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'server/discover',
        ], $headers);

        if ($version !== null && ! in_array($version, $supported, true)) {
            $initialized->assertBadRequest()->assertJsonPath('error.code', -32022);
            $discovered->assertBadRequest()->assertJsonPath('error.code', -32022);
            $this->assertSame($initialized->json('error.data'), $discovered->json('error.data'));

            return;
        }

        $initialized->assertOk()->assertJsonPath('result.protocolVersion', $version ?? ProtocolVersion::latestHandshake()->value);
        $discovered->assertOk()->assertJsonPath('result.supportedVersions', $supported);
        $this->assertSame($initialized->json('result.protocolVersion'), $discovered->json('result.protocolVersion'));
        $this->assertSame($initialized->json('result.serverInfo'), $discovered->json('result.serverInfo'));
        $this->assertSame($initialized->json('result.capabilities'), $discovered->json('result.capabilities'));
        $this->assertSame($initialized->json('result.instructions'), $discovered->json('result.instructions'));
        $this->assertNull($discovered->headers->get('Mcp-Session-Id'));
    }

    public function test_discovery_metadata_tracks_the_connections_available_tools_and_prompts(): void
    {
        foreach ([
            [AgentApiScopes::MCP_USE],
            [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::CLINICAL_READ, AgentApiScopes::CLINICAL_WRITE],
        ] as $scopes) {
            Passport::actingAs(User::factory()->create(['user_role' => 'user']), $scopes);
            $initialized = $this->postJson('/api/v1/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25', 'capabilities' => (object) [],
                    'clientInfo' => ['name' => 'Synthetic discovery client', 'version' => '1'],
                ],
            ])->assertOk();
            $discovered = $this->postJson('/api/v1/mcp', [
                'jsonrpc' => '2.0', 'id' => 2, 'method' => 'server/discover',
            ])->assertOk();

            $this->assertSame($initialized->json('result'), array_diff_key($discovered->json('result'), ['supportedVersions' => true]));
            $this->assertArrayHasKey('tools', $discovered->json('result.capabilities'));
            $this->assertSame(count($scopes) > 1, array_key_exists('prompts', $discovered->json('result.capabilities')));
        }
    }

    public function test_discovery_rejects_invalid_messages_and_oversized_bodies(): void
    {
        Passport::actingAs(User::factory()->create(['user_role' => 'user']), [AgentApiScopes::MCP_USE]);
        foreach ([
            ['jsonrpc' => '1.0', 'id' => 1],
            ['jsonrpc' => '2.0', 'id' => []],
            ['jsonrpc' => '2.0'],
            ['jsonrpc' => '2.0', 'id' => 1, 'params' => 'invalid'],
            ['jsonrpc' => '2.0', 'id' => 1, 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]],
        ] as $message) {
            $this->postJson('/api/v1/mcp', ['method' => 'server/discover', ...$message])->assertBadRequest();
        }

        config(['agent_api.mcp_max_body_bytes' => 32]);
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover',
        ])->assertStatus(413);
    }
}
