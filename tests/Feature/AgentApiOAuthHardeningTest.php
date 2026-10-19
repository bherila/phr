<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * Authorization-server hardening for first-party clients (F2): no userless
 * client_credentials tokens, RFC 9207 `iss` on authorization responses, and a
 * command that registers a first-party client as authorization-code only.
 */
final class AgentApiOAuthHardeningTest extends TestCase
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

    public function test_the_token_endpoint_refuses_client_credentials_even_for_a_client_registered_for_it(): void
    {
        // A client row that does list the grant, as a misregistration might:
        // the refusal must not depend on the row.
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Synthetic Machine Client');

        $this->postJson('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => AgentApiScopes::IDENTITY_READ,
        ])->assertBadRequest()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('error', 'unsupported_grant_type');

        $this->withHeader('Authorization', 'Basic '.base64_encode($client->id.':'.$client->plainSecret))
            ->post('/oauth/token', ['grant_type' => 'client_credentials'])
            ->assertBadRequest()
            ->assertJsonPath('error', 'unsupported_grant_type');

        $this->assertSame(0, Token::query()->count());
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
    }
}
