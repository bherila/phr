<?php

namespace Tests\Feature;

use App\Http\Middleware\ExpectAnyOAuthResource;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\Http\Middleware\ExpectOAuthResource;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\Testing\AssertsAgentOAuthContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * The REST API (APP_URL/api/v1) and the MCP endpoint (APP_URL/api/v1/mcp) are
 * separate protected resources: each challenge leads a strict RFC 9728 client
 * to a document naming exactly the URL it called, and a credential issued for
 * one is refused at the other.
 */
class AgentApiProtectedResourcesTest extends TestCase
{
    use AssertsAgentOAuthContract;
    use RefreshDatabase;

    private const string REDIRECT_URI = 'https://agent.example.test/callback';

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral in-memory signing keys, as in the other OAuth suites.
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config([
            'passport.private_key' => $privateKey,
            'passport.public_key' => $details['key'],
        ]);
    }

    public function test_each_endpoint_challenges_with_the_metadata_of_its_own_resource(): void
    {
        $this->assertSame(url('/api/v1/mcp'), OAuthResourceIndicator::resource('mcp'));
        $this->assertSame(url('/api/v1'), OAuthResourceIndicator::resource('rest'));

        $this->assertProtectedResourceChallenge('POST', '/api/v1/mcp', url('/api/v1/mcp'));
        $this->assertProtectedResourceChallenge('GET', '/api/v1/me', url('/api/v1'));
        $this->assertProtectedResourceChallenge('GET', '/api/v1/patients', url('/api/v1'));
        $this->assertProtectedResourceChallenge('GET', '/api/v1/genai/queue/status', url('/api/v1'));
    }

    public function test_an_mcp_credential_is_refused_at_rest_and_a_rest_credential_at_mcp(): void
    {
        $user = User::factory()->create([
            'name' => 'Synthetic Audience User',
            'email' => 'audience-user@example.test',
            'user_role' => 'user',
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Audience Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);

        $restToken = $this->issue($user, $client, AgentApiScopes::IDENTITY_READ, null);
        $mcpToken = $this->issue($user, $client, AgentApiScopes::MCP_USE.' '.AgentApiScopes::IDENTITY_READ, url('/api/v1/mcp'));
        $this->assertEqualsCanonicalizing(
            [url('/api/v1'), url('/api/v1/mcp')],
            Token::query()->where('user_id', $user->id)->pluck('resource_uri')->all(),
        );

        Auth::forgetGuards();
        $this->withToken($restToken)->getJson('/api/v1/me')->assertOk();
        Auth::forgetGuards();
        $session = $this->withToken($mcpToken)->postJson('/api/v1/mcp', $this->initializeMessage(), ['Mcp-Protocol-Version' => '2025-06-18'])
            ->assertOk()
            ->assertJsonPath('id', 1)
            ->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);
        // MCP tools adapt the REST operations by re-dispatching them internally
        // with the connection's credential; the MCP audience must carry through.
        Auth::forgetGuards();
        $this->withToken($mcpToken)->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'identity.get', 'arguments' => (object) []],
        ], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session])
            ->assertOk()
            ->assertJsonPath('id', 2)
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.identity.email', 'audience-user@example.test');

        // The MCP credential carries identity:read, yet REST refuses its audience.
        Auth::forgetGuards();
        $this->withToken($mcpToken)->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
        // A REST credential never reaches the MCP endpoint.
        Auth::forgetGuards();
        $challenge = $this->withToken($restToken)->postJson('/api/v1/mcp', $this->initializeMessage(), ['Mcp-Protocol-Version' => '2025-06-18'])
            ->assertUnauthorized()
            ->headers->get('WWW-Authenticate');
        $this->assertStringContainsString(
            'resource_metadata="'.url('/.well-known/oauth-protected-resource/api/v1/mcp').'"',
            (string) $challenge,
        );
    }

    public function test_routes_an_mcp_tool_hands_to_its_client_accept_either_credential(): void
    {
        $user = User::factory()->create([
            'name' => 'Synthetic Handoff User',
            'email' => 'handoff-user@example.test',
            'user_role' => 'user',
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Handoff Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $scopes = AgentApiScopes::GENAI_READ.' '.AgentApiScopes::IDENTITY_READ;
        $mcpToken = $this->issue($user, $client, AgentApiScopes::MCP_USE.' '.$scopes, url('/api/v1/mcp'));
        $restToken = $this->issue($user, $client, $scopes, null);

        foreach ([$mcpToken, $restToken] as $token) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson('/api/v1/genai/queue/status')->assertOk();
        }
        // Self-revocation works with the connection's own credential.
        Auth::forgetGuards();
        $this->withToken($mcpToken)->deleteJson('/api/v1/oauth/token')->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($mcpToken)->getJson('/api/v1/genai/queue/status')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($restToken)->getJson('/api/v1/me')->assertOk();
    }

    /**
     * Every authenticated agent route declares its audience: MCP only at the MCP
     * endpoint, either audience only on the routes an MCP tool hands to its
     * client, and REST only everywhere else.
     */
    public function test_every_agent_route_declares_exactly_its_protected_resource(): void
    {
        $handoff = [
            'agent-api.v1.oauth-token.destroy',
            'agent-api.v1.documents.file',
            'agent-api.v1.exports.file',
            'agent-api.v1.native-backups.file',
            'agent-api.v1.dicom-uploads.files.store',
        ];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (! in_array('auth:api', $middleware, true)) {
                continue;
            }
            $name = (string) $route->getName();
            $declared = array_values(array_filter(
                $middleware,
                static fn (mixed $entry): bool => is_string($entry)
                    && (str_starts_with($entry, ExpectOAuthResource::class) || str_starts_with($entry, ExpectAnyOAuthResource::class)),
            ));
            $expected = match (true) {
                $name === 'agent-api.v1.mcp' => [ExpectOAuthResource::class.':mcp'],
                in_array($name, $handoff, true), str_starts_with($route->uri(), 'api/v1/genai') => [ExpectAnyOAuthResource::class.':rest,mcp'],
                default => [ExpectOAuthResource::class.':rest'],
            };
            $this->assertSame($expected, $declared, "{$route->uri()} ({$name}) declares its protected resource");
            $checked++;
        }
        $this->assertGreaterThan(count($handoff) + 1, $checked);
    }

    /** Authorize and exchange a code, naming `$resource` on both legs (or omitting it). */
    private function issue(User $user, Client $client, string $scope, ?string $resource): string
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $authorize = [
            'client_id' => $client->id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => 'synthetic-audience-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        if ($resource !== null) {
            $authorize['resource'] = $resource;
        }
        $authorization = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($authorize));
        // Passport skips the consent screen once the client holds those scopes.
        $approval = $authorization->isRedirect()
            ? $authorization
            : $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null);

        $exchange = [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
            'code' => $query['code'],
        ];
        if ($resource !== null) {
            $exchange['resource'] = $resource;
        }
        $token = $this->postJson('/oauth/token', $exchange)->assertOk()->json('access_token');
        $this->assertIsString($token);

        return $token;
    }

    /** @return array<string, mixed> */
    private function initializeMessage(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'Synthetic Audience Client', 'version' => '1.0.0'],
            ],
        ];
    }
}
