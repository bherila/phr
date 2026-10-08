<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\Testing\AssertsAgentOAuthContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The flow a generic connector follows - self-registration, S256 PKCE and no
 * `resource` parameter - yields credentials bound to APP_URL/api/v1 that the
 * API accepts, and they refresh.
 */
final class AgentOAuthPresetContractTest extends TestCase
{
    use AssertsAgentOAuthContract;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ephemeral in-memory keys; CI has no production signing keys.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $details['key']]);
    }

    public function test_discovery_follows_the_preset(): void
    {
        $this->assertAgentOAuthDiscovery();
        $this->assertSame(url('/api/v1'), config('bherila-auth.oauth_server.resource'));
        $this->assertTrue((bool) config('bherila-auth.oauth_server.assume_omitted_resource'));
    }

    public function test_a_connector_that_omits_resource_gets_credentials_the_api_accepts(): void
    {
        $user = $this->createUser(['email' => 'preset-contract@example.test']);

        $tokens = $this->assertAgentOAuthLifecycle($user, AgentApiScopes::IDENTITY_READ, '/api/v1/me');

        $this->assertNotEmpty($tokens['access_token'] ?? null);
    }
}
