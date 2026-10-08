<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Client;
use Tests\TestCase;

/**
 * A signed-in person creates their own API tokens and OAuth apps. Tokens are
 * bound to the API resource and pass the same account checks as any OAuth
 * credential; apps are held to the permissions chosen for them.
 */
final class ApiCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $details['key']]);
    }

    public function test_the_index_offers_api_scopes_but_not_the_mcp_connection(): void
    {
        $user = $this->createUser(['email' => 'credentials-index@example.test']);

        $index = $this->actingAs($user)->getJson('/account/api-credentials')->assertOk()->json('data');

        $offered = array_column($index['scopes'], 'id');
        $this->assertContains(AgentApiScopes::PATIENTS_READ, $offered);
        $this->assertNotContains(AgentApiScopes::MCP_USE, $offered);
        $this->assertContains('PT4H', $index['token_lifetimes']);
    }

    public function test_a_personal_token_is_bound_to_the_api_and_revocable(): void
    {
        $user = $this->createUser(['email' => 'credentials-token@example.test']);

        $issued = $this->actingAs($user)->postJson('/account/api-credentials/tokens', [
            'name' => 'Synthetic connector',
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'lifetime' => 'P30D',
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');

        $this->assertSame(OAuthResourceIndicator::resource(), OAuthResourceIndicator::tokenClaims($issued['token'])['resource'] ?? null);
        Auth::forgetGuards();
        $this->app['session.store']->flush();
        $this->withToken($issued['token'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('identity.email', 'credentials-token@example.test');

        // The document offers API tokens on REST operations only: the MCP
        // connection scope is never grantable to one.
        Auth::forgetGuards();
        $this->withToken($issued['token'])->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1']],
        ], ['Mcp-Protocol-Version' => '2025-06-18'])->assertForbidden();

        Auth::forgetGuards();
        $listed = $this->actingAs($user)->getJson('/account/api-credentials')->assertOk()->json('data.tokens');
        $this->assertCount(1, $listed);
        $this->actingAs($user)->deleteJson($listed[0]['revoke_href'])->assertSuccessful();
        Auth::forgetGuards();
        $this->withToken($issued['token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_an_app_is_owned_by_its_creator_and_holds_its_chosen_scopes(): void
    {
        $user = $this->createUser(['email' => 'credentials-app@example.test']);

        $app = $this->actingAs($user)->postJson('/account/api-credentials/apps', [
            'name' => 'Synthetic REST connector',
            'redirect_uris' => ['https://connector.example.test/callback'],
            'confidential' => true,
            'scopes' => [AgentApiScopes::PATIENTS_READ],
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($app['client_secret'] ?? null);
        $client = Client::query()->findOrFail($app['client_id']);
        $this->assertSame([AgentApiScopes::PATIENTS_READ], $client->scopes);
        $this->assertSame((string) $user->getKey(), (string) $client->owner_id);
    }

    public function test_a_personal_token_dies_with_its_account_and_stays_dead_after_reenabling(): void
    {
        // User id 1 is always an administrator and cannot be disabled.
        $this->createAdminUser();
        $user = $this->createUser(['email' => 'credentials-disabled@example.test']);
        $token = $this->actingAs($user)->postJson('/account/api-credentials/tokens', [
            'name' => 'Synthetic connector',
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'lifetime' => 'P30D',
        ])->assertCreated()->json('data.token');

        $user->forceFill(['user_role' => ''])->save();
        Auth::forgetGuards();
        $this->app['session.store']->flush();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

        $user->forceFill(['user_role' => 'user'])->save();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    /** The page reads refusals from JSON; a redirect would be followed and read as success. */
    public function test_a_refused_request_answers_json_not_a_redirect(): void
    {
        $user = $this->createUser(['email' => 'credentials-refusal@example.test']);

        $this->actingAs($user)->postJson('/account/api-credentials/tokens', [
            'name' => 'Synthetic MCP attempt',
            'scopes' => [AgentApiScopes::MCP_USE],
            'lifetime' => 'P30D',
        ])->assertUnprocessable()->assertJsonValidationErrors('scopes.0');
    }
}
