<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthAuthorizationStateStore;
use App\Support\AgentApi\OAuthDynamicClientDao;
use App\Support\AgentApi\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Tests\TestCase;

final class AgentApiOAuthClientRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_discovery_advertises_public_registration_and_resource_indicators(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('resource_indicators_supported', true)
            ->assertJsonPath('scopes_supported', AgentApiScopes::ids());

        $this->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')->assertNotFound();
        $this->assertNotContains(AgentApiScopes::MCP_USE, AgentApiScopes::ids());
        $this->assertArrayHasKey(AgentApiScopes::MCP_USE, AgentApiScopes::reservedDescriptions());
    }

    public function test_dynamic_registration_issues_only_a_public_bounded_client(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Synthetic Remote Agent',
            'redirect_uris' => [
                'https://agent.example.test/oauth/callback',
                'http://127.0.0.1:48731/callback',
                'http://[::1]:48732/callback',
            ],
            'grant_types' => ['refresh_token', 'authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => AgentApiScopes::PATIENTS_READ,
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonMissingPath('client_secret')
            ->assertJsonMissingPath('registration_access_token')
            ->assertJsonPath('client_name', 'Synthetic Remote Agent')
            ->assertJsonPath('token_endpoint_auth_method', 'none');

        $client = Client::query()->findOrFail($response->json('client_id'));
        $this->assertFalse($client->confidential());
        $this->assertSame(['authorization_code', 'refresh_token'], $client->grant_types);
        $this->assertSame([
            'https://agent.example.test/oauth/callback',
            'http://127.0.0.1:48731/callback',
            'http://[::1]:48732/callback',
        ], $client->redirect_uris);
        $this->assertNotNull($client->dynamically_registered_at);
        $this->assertSame(
            [AgentApiScopes::PATIENTS_READ],
            $client->scopes,
        );

        $challenge = $this->pkce()[1];
        $user = User::factory()->create([
            'name' => 'Synthetic Registration User',
            'email' => 'registration-user@example.test',
            'user_role' => 'user',
        ]);
        $authorization = [
            'client_id' => $client->id,
            'redirect_uri' => 'https://agent.example.test/oauth/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::PATIENTS_READ,
            'state' => 'synthetic-registration-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::agentApi(),
        ];
        $withoutScope = $authorization;
        unset($withoutScope['scope']);
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($withoutScope))
            ->assertOk();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            ...$authorization,
            'scope' => AgentApiScopes::DOCUMENTS_READ,
        ]))->assertBadRequest()
            ->assertJsonPath('error', 'invalid_scope');

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($authorization))->assertOk()
            ->assertSee('This client registered automatically')
            ->assertSee('https://agent.example.test/oauth/callback')
            ->assertDontSee('http://127.0.0.1:48731/callback')
            ->assertDontSee('http://[::1]:48732/callback');

        $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
        ])->assertRedirect();
        $this->assertNotNull($client->fresh()?->first_authorized_at);
    }

    public function test_dynamic_registration_omits_unsupplied_scope_metadata(): void
    {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'Synthetic Unscoped Agent',
            'redirect_uris' => ['https://agent.example.test/callback'],
        ])->assertCreated()
            ->assertJsonMissingPath('scope');

        $client = Client::query()->findOrFail($response->json('client_id'));
        $this->assertNull($client->scopes);
    }

    public function test_dynamic_registration_rejects_unsafe_or_unsupported_metadata_generically(): void
    {
        $valid = [
            'client_name' => 'Synthetic Invalid Agent',
            'redirect_uris' => ['https://agent.example.test/callback'],
        ];
        $this->call('POST', '/oauth/register', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($valid, JSON_THROW_ON_ERROR))
            ->assertBadRequest()->assertJsonPath('error', 'invalid_client_metadata');
        $this->postJson('/oauth/register', [
            ...$valid,
            'redirect_uris' => ['http://agent.example.test/callback'],
        ])->assertBadRequest()->assertJsonPath('error_description', 'Client metadata is invalid.');
        $this->postJson('/oauth/register', [
            ...$valid,
            'redirect_uris' => ['https://agent.example.test/callback#fragment'],
        ])->assertBadRequest();
        $this->postJson('/oauth/register', [
            ...$valid,
            'grant_types' => ['authorization_code', 'client_credentials'],
        ])->assertBadRequest();
        $this->postJson('/oauth/register', [
            ...$valid,
            'scope' => AgentApiScopes::PATIENTS_READ.' unknown:scope',
        ])->assertBadRequest();
        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_resource_indicator_persists_across_authorization_and_rotation(): void
    {
        $user = User::factory()->create([
            'name' => 'Synthetic Resource User',
            'email' => 'resource-user@example.test',
            'user_role' => 'user',
        ]);
        $client = $this->publicClient('Synthetic Resource Client');
        [$verifier, $challenge] = $this->pkce();

        $query = [
            'client_id' => $client->id,
            'redirect_uri' => 'https://agent.example.test/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-resource-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            ...$query,
            'resource' => 'https://unrelated.example.test/api',
        ]))->assertBadRequest()->assertJsonPath('error_description', 'The requested resource is invalid.');

        $approval = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            ...$query,
            'resource' => OAuthResourceIndicator::agentApi().'/',
        ]))->assertOk();
        $this->assertNotNull($approval);
        $authToken = session('authToken');
        $this->travel(11)->minutes();
        $redirect = $this->post('/oauth/authorize', [
            'auth_token' => $authToken,
        ])->assertRedirect();
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);
        $this->assertIsString($redirectQuery['code']);
        $this->assertSame(OAuthResourceIndicator::agentApi(), AuthCode::query()->sole()->resource_uri);

        $issued = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://agent.example.test/callback',
            'code_verifier' => $verifier,
            'code' => $redirectQuery['code'],
            'resource' => OAuthResourceIndicator::agentApi(),
        ])->assertOk()->json();
        $first = Token::query()->where('user_id', $user->id)->sole();
        $this->assertSame(OAuthResourceIndicator::agentApi(), $first->resource_uri);

        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $issued['refresh_token'],
            'resource' => OAuthResourceIndicator::agentApi().'/',
        ])->assertOk();
        $this->assertSame(
            [OAuthResourceIndicator::agentApi(), OAuthResourceIndicator::agentApi()],
            Token::query()->where('user_id', $user->id)->orderBy('created_at')->pluck('resource_uri')->all(),
        );
    }

    public function test_concurrent_approval_reads_keep_the_resource_indicator_bound(): void
    {
        $state = app(OAuthAuthorizationStateStore::class);
        $state->rememberResource('synthetic-concurrent-auth-token', OAuthResourceIndicator::agentApi());

        $this->assertSame(
            OAuthResourceIndicator::agentApi(),
            $state->resourceFor('synthetic-concurrent-auth-token'),
        );
        $this->assertSame(
            OAuthResourceIndicator::agentApi(),
            $state->resourceFor('synthetic-concurrent-auth-token'),
        );
    }

    public function test_dynamic_registration_has_a_dedicated_pre_authentication_limit(): void
    {
        config(['agent_api.client_registrations_per_hour' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44']);
        $metadata = [
            'client_name' => 'Synthetic Limited Agent',
            'redirect_uris' => ['https://agent.example.test/callback'],
        ];

        $this->postJson('/oauth/register', $metadata)->assertCreated();
        $this->postJson('/oauth/register', $metadata)->assertCreated();
        $this->postJson('/oauth/register', $metadata)->assertTooManyRequests();
        $this->assertDatabaseCount('oauth_clients', 2);
    }

    public function test_resource_bound_authorization_code_cannot_be_exchanged_without_its_audience(): void
    {
        $user = User::factory()->create([
            'name' => 'Synthetic Missing Resource User',
            'email' => 'missing-resource@example.test',
            'user_role' => 'user',
        ]);
        $client = $this->publicClient('Synthetic Missing Resource Client');
        [$verifier, $challenge] = $this->pkce();
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://agent.example.test/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-missing-resource-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::agentApi(),
        ]))->assertOk();
        $redirect = $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
        ])->assertRedirect();
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://agent.example.test/callback',
            'code_verifier' => $verifier,
            'code' => $redirectQuery['code'],
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertTrue(AuthCode::query()->sole()->revoked);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_credential_pruning_removes_only_unused_stale_dynamic_clients(): void
    {
        $stale = $this->publicClient('Synthetic Stale Dynamic Client');
        $stale->forceFill(['dynamically_registered_at' => now()->subDays(2)])->save();
        $recent = $this->publicClient('Synthetic Recent Dynamic Client');
        $recent->forceFill(['dynamically_registered_at' => now()])->save();
        $previouslyUsed = $this->publicClient('Synthetic Previously Used Dynamic Client');
        $previouslyUsed->forceFill([
            'dynamically_registered_at' => now()->subDays(30),
            'first_authorized_at' => now()->subDays(29),
        ])->save();
        $rechecked = $this->publicClient('Synthetic Rechecked Dynamic Client');
        $rechecked->forceFill(['dynamically_registered_at' => now()->subDays(2)])->save();
        $static = $this->publicClient('Synthetic Static Client');

        $dynamicClients = app(OAuthDynamicClientDao::class);
        $this->assertContains($rechecked->id, $dynamicClients->staleUnusedIds(now()->subDay()));
        $rechecked->forceFill(['first_authorized_at' => now()])->save();
        $this->assertNull($dynamicClients->lockUnusedForPruning($rechecked->id, now()->subDay()));

        $this->artisan('phr:agent-api:prune-oauth-credentials')->assertSuccessful();

        $this->assertNull($stale->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($previouslyUsed->fresh());
        $this->assertNotNull($rechecked->fresh());
        $this->assertNotNull($static->fresh());
    }

    private function publicClient(string $name): Client
    {
        return Client::query()->create([
            'name' => $name,
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://agent.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }

    /** @return array{string, string} */
    private function pkce(): array
    {
        $verifier = str_repeat('synthetic-resource-verifier-', 2);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }
}
