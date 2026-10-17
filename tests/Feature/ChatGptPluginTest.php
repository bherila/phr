<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * The ChatGPT plugin in integrations/chatgpt-plugin is plain text that names
 * the MCP endpoint, its OAuth scopes, and the tools its skill teaches. Nothing
 * else ties it to the server, so a renamed tool or scope would ship a plugin
 * that teaches calls the connection cannot make.
 */
final class ChatGptPluginTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    private const string PLUGIN = __DIR__.'/../../integrations/chatgpt-plugin';

    /** @return array<string, mixed> */
    private static function pluginFile(string $file): array
    {
        return json_decode((string) file_get_contents(self::PLUGIN.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function server(): array
    {
        $servers = self::pluginFile('mcp.json')['mcpServers'];
        self::assertCount(1, $servers, 'A plugin carries exactly one MCP server.');

        return array_values($servers)[0];
    }

    public function test_the_manifest_references_only_packaged_files(): void
    {
        $manifest = self::pluginFile('plugin.json');
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $manifest['name']);

        $openai = $manifest['extensions']['com.openai'];
        foreach ([$openai['onboardingSkill'], $openai['interface']['logo'], $openai['interface']['composerIcon']] as $path) {
            $this->assertStringStartsWith('./', $path);
            $this->assertStringNotContainsString('..', $path);
            $this->assertFileExists(self::PLUGIN.'/'.substr($path, 2));
        }

        $skill = (string) file_get_contents(self::PLUGIN.'/'.substr($openai['onboardingSkill'], 2));
        $this->assertMatchesRegularExpression('/\A---\nname: [a-z0-9-]+\ndescription: .+\n---\n/', $skill);
    }

    public function test_the_server_is_this_apps_mcp_resource_with_known_scopes(): void
    {
        $server = self::server();
        $auth = $server['extensions']['com.openai']['auth'];

        $this->assertSame('streamable-http', $server['type']);
        $this->assertSame('/api/v1/mcp', parse_url($server['url'], PHP_URL_PATH));
        $this->assertSame('https', parse_url($server['url'], PHP_URL_SCHEME));
        // The endpoint is its own protected resource; a token for any other
        // resource is refused, so the two must name the same URL.
        $this->assertSame($server['url'], $auth['resource']);
        $this->assertSame('oauth', $auth['type']);

        $scopes = $auth['defaultScopes'];
        $this->assertContains(AgentApiScopes::MCP_USE, $scopes);
        $this->assertSame([], array_values(array_diff($scopes, AgentApiScopes::ids())), 'Unknown scope in defaultScopes.');
        $this->assertSame(array_values(array_unique($scopes)), $scopes);
    }

    public function test_every_tool_the_skill_names_is_exposed_to_the_default_scopes(): void
    {
        $this->configurePassportKeys();
        $client = Client::query()->create([
            'name' => 'Synthetic ChatGPT Plugin Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Passport::actingAs(User::factory()->create(['user_role' => 'user']), self::server()['extensions']['com.openai']['auth']['defaultScopes'], 'api', $client);
        $exposed = $this->exposedToolNames();

        $skill = (string) file_get_contents(self::PLUGIN.'/skills/phr-health-records/SKILL.md');
        preg_match_all('/`([a-z_]+(?:\.[a-z_]+)+)`/', $skill, $tools);
        $this->assertNotEmpty($tools[1]);
        foreach (array_unique($tools[1]) as $tool) {
            $this->assertContains($tool, $exposed, "The skill names {$tool}, which the default scopes do not expose.");
        }

        // Placeholders such as `<type>.upsert` must still match real tools.
        preg_match_all('/`<type>\.([a-z_]+)`/', $skill, $suffixes);
        foreach (array_unique($suffixes[1]) as $suffix) {
            foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
                $this->assertContains(str_replace('-', '_', $resource).'.'.$suffix, $exposed);
            }
        }

        preg_match('/\(`([a-z-]+)`(?:,\s*`[a-z-]+`)*\)/', $skill, $listed);
        preg_match_all('/`([a-z-]+)`/', $listed[0] ?? '', $types);
        $this->assertSame(AgentClinicalResourceCatalog::ids(), $types[1], 'The skill must list exactly the clinical resource types.');
    }

    /** @return list<string> */
    private function exposedToolNames(): array
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        $initialized = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic Plugin Check', 'version' => '1.0.0']],
        ], $headers)->assertOk();
        $headers['Mcp-Session-Id'] = (string) $initialized->headers->get('Mcp-Session-Id');
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);

        $names = [];
        $cursor = null;
        $id = 2;
        do {
            $page = $this->postJson('/api/v1/mcp', [
                'jsonrpc' => '2.0', 'id' => $id++, 'method' => 'tools/list',
                'params' => $cursor === null ? new \stdClass : ['cursor' => $cursor],
            ], $headers)->assertOk()->json('result');
            $names = [...$names, ...array_column($page['tools'], 'name')];
            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor !== null);

        return $names;
    }
}
