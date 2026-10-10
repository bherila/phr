<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\OpenApi\OpenApiDocumentBuilder;
use Bherila\McpLaravelBridge\OpenApi\OpenApiSettings;
use Bherila\McpLaravelBridge\OpenApi\SchemaCatalog;

/**
 * The agent API contract, generated from the operation registry.
 *
 * Paths, security, parameters, responses and prose come from the registry's
 * declarations ({@see AgentRestDocumentation}); schemas from the components
 * of `public/openapi/phr-agent-v1.json`, which is also the checked copy of the
 * generated document and fails a test when the two drift apart.
 */
final class AgentOpenApi
{
    public const string CHECKED_DOCUMENT = 'openapi/phr-agent-v1.json';

    /** The document addressed to this installation, from configuration. */
    public static function forInstallation(): OpenApiDocumentBuilder
    {
        return self::builder(
            (string) config('bherila-auth.oauth_server.resource'),
            (string) config('bherila-auth.oauth_server.authorization_endpoint'),
            (string) config('bherila-auth.oauth_server.token_endpoint'),
        );
    }

    /** The document as checked in, with relative addresses. */
    public static function checked(): OpenApiDocumentBuilder
    {
        return self::builder('/api/v1', '/oauth/authorize', '/oauth/token');
    }

    private static function builder(string $serverUrl, string $authorizationUrl, string $tokenUrl): OpenApiDocumentBuilder
    {
        return new OpenApiDocumentBuilder(app(AgentOperations::class)->registry(), new OpenApiSettings(
            title: 'PHR Agent API',
            version: '1.0.0',
            serverUrl: $serverUrl,
            description: 'Versioned OAuth API for authorized PHR automation clients. Responses containing personal data are private and non-cacheable.',
            authorizationUrl: $authorizationUrl,
            tokenUrl: $tokenUrl,
            scopes: AgentApiScopes::descriptions(),
            apiTokenDescription: 'A personal API token created under API Access in the account settings. It carries the permissions chosen when it was created and expires on the date shown there. It is not accepted by the MCP endpoint.',
            connectionScopes: [AgentApiScopes::MCP_USE],
            summaries: false,
            agentExtensions: false,
            parameters: AgentRestDocumentation::parameters(),
            responses: AgentRestDocumentation::responses(),
            oauthDescription: 'OAuth 2.1 Authorization Code with S256 PKCE. Access tokens expire after 15 minutes and refresh tokens rotate on use.',
        ), new SchemaCatalog(public_path(self::CHECKED_DOCUMENT)));
    }
}
