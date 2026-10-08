<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use Tests\TestCase;

/**
 * The served contract describes the installation it came from, and every
 * REST operation a personal API token can call says so.
 */
final class AgentApiOpenApiDocumentTest extends TestCase
{
    public function test_the_document_carries_this_installations_urls(): void
    {
        $document = $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->json();

        $this->assertSame([['url' => url('/api/v1')]], $document['servers']);
        $flow = $document['components']['securitySchemes']['oauth2']['flows']['authorizationCode'];
        $this->assertSame(url('/oauth/authorize'), $flow['authorizationUrl']);
        $this->assertSame(url('/oauth/token'), $flow['tokenUrl']);
        $this->assertSame(url('/oauth/token'), $flow['refreshUrl']);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('openapi_url', url('/api/openapi.json'));
    }

    public function test_a_fork_on_another_origin_is_described_by_its_own_configuration(): void
    {
        config([
            'bherila-auth.oauth_server.resource' => 'https://health.example.test/api/v1',
            'bherila-auth.oauth_server.authorization_endpoint' => 'https://health.example.test/oauth/authorize',
            'bherila-auth.oauth_server.token_endpoint' => 'https://health.example.test/oauth/token',
        ]);

        $document = $this->getJson('/api/openapi.json')->assertOk()->json();

        $this->assertSame('https://health.example.test/api/v1', $document['servers'][0]['url']);
        $this->assertSame('https://health.example.test/oauth/authorize', $document['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['authorizationUrl']);
    }

    /** A personal token never holds the MCP connection scope, so MCP operations do not offer it. */
    public function test_every_rest_operation_accepts_an_api_token_and_mcp_operations_do_not(): void
    {
        $document = $this->getJson('/api/openapi.json')->assertOk()->json();
        $this->assertSame(['type' => 'http', 'scheme' => 'bearer'], array_intersect_key($document['components']['securitySchemes']['apiToken'], ['type' => 1, 'scheme' => 1]));

        $checked = 0;
        foreach ($document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! is_array($operation) || ! isset($operation['operationId']) || ($operation['security'] ?? []) === []) {
                    continue;
                }
                $schemes = array_merge(...array_map(array_keys(...), $operation['security']));
                $mcp = in_array(AgentApiScopes::MCP_USE, $operation['security'][0]['oauth2'] ?? [], true);
                $this->assertSame($mcp ? ['oauth2'] : ['oauth2', 'apiToken'], $schemes, "{$method} {$path}");
                $checked++;
            }
        }
        $this->assertGreaterThan(60, $checked);
    }
}
