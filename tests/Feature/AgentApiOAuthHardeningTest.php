<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
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

    public function test_authorization_responses_carry_the_issuer_and_metadata_advertises_it(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', url('/'))
            ->assertJsonPath('authorization_response_iss_parameter_supported', true);

        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Issuer Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $query = [
            'client_id' => $client->id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-issuer-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('i', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];

        // Approval.
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $approved->headers->get('Location'), PHP_URL_QUERY), $success);
        $this->assertIsString($success['code'] ?? null);
        $this->assertSame(url('/'), $success['iss'] ?? null);

        // Denial.
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $denied = $this->delete('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $denied->headers->get('Location'), PHP_URL_QUERY), $error);
        $this->assertSame('access_denied', $error['error'] ?? null);
        $this->assertSame(url('/'), $error['iss'] ?? null);
    }
}
