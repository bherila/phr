<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\Testing\OperationRegistryAssertions;
use Tests\TestCase;

/**
 * The agent API contract is generated from the operation registry, and
 * `public/openapi/phr-agent-v1.json` is its checked copy: a declaration change
 * shows up as a reviewed diff of that file, and the two never drift apart.
 *
 * Regenerate the checked copy with UPDATE_OPERATION_SNAPSHOTS=1.
 */
final class AgentApiOpenApiGenerationTest extends TestCase
{
    use OperationRegistryAssertions;

    public function test_the_checked_document_is_exactly_the_generated_one(): void
    {
        self::assertOpenApiDocumentMatches(AgentOpenApi::checked()->full(), public_path(AgentOpenApi::CHECKED_DOCUMENT));
    }

    public function test_the_served_document_differs_from_the_checked_one_only_in_its_addresses(): void
    {
        config([
            'bherila-auth.oauth_server.resource' => 'https://health.example.test/api/v1',
            'bherila-auth.oauth_server.authorization_endpoint' => 'https://login.example.test/authorize',
            'bherila-auth.oauth_server.token_endpoint' => 'https://login.example.test/token',
        ]);

        $this->assertSame([
            '/servers/0/url: differs',
            '/components/securitySchemes/oauth2/flows/authorizationCode/authorizationUrl: differs',
            '/components/securitySchemes/oauth2/flows/authorizationCode/tokenUrl: differs',
            '/components/securitySchemes/oauth2/flows/authorizationCode/refreshUrl: differs',
        ], OpenApiDocumentBuilder::differences(AgentOpenApi::forInstallation()->full(), public_path(AgentOpenApi::CHECKED_DOCUMENT)));
        $this->assertSame(AgentOpenApi::forInstallation()->full()['paths'], $this->getJson('/api/openapi.json')->assertOk()->json('paths'), 'The route serves the generated document');
    }
}
