<?php

namespace Tests\Feature;

use App\Models\OAuthClientConsent;
use App\Models\OAuthTokenFamily;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Once;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

/** Operator revocation through the real issuance and protected-resource routes. */
final class AgentApiFirstPartyClientRevocationTest extends TestCase
{
    use RefreshDatabase;

    private const string CALLBACK = 'https://finance.example.test/oauth/phr/callback';

    private const string VERIFIER = 'synthetic-consent-verifier-synthetic-consent-verifier';

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

    public function test_revocation_disables_only_the_named_registration_and_all_its_grants(): void
    {
        $user = $this->createUser();
        $secondUser = $this->createUser();
        $target = $this->firstPartyClient(allowListed: false);
        $other = $this->firstPartyClient(name: 'Synthetic Unrelated Client');
        $this->authorize($user, $target, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $issued = $this->exchange($target, $this->approve())->assertOk()->json();
        $pendingCode = $this->codeFrom($this->authorize($user, $target, [AgentApiScopes::IDENTITY_READ])->assertRedirect());
        OAuthClientConsent::query()->create([
            'user_id' => $secondUser->id, 'client_id' => $target->id,
            'resource' => url('/api/v1'), 'scopes' => [AgentApiScopes::IDENTITY_READ],
            'version' => 1, 'granted_at' => now(),
        ]);
        Token::query()->create([
            'id' => 'synthetic-second-user-token', 'user_id' => $secondUser->id,
            'client_id' => $target->id, 'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false, 'expires_at' => now()->addHour(),
        ]);
        Token::query()->create([
            'id' => 'synthetic-unrelated-user-token', 'user_id' => $secondUser->id,
            'client_id' => $other->id, 'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false, 'expires_at' => now()->addHour(),
        ]);
        $this->authorize($user, $other, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $unrelated = $this->exchange($other, $this->approve())->assertOk()->json();

        $this->assertSame(0, Artisan::call('phr:agent-api:revoke-first-party-client', ['client_id' => $target->id]));
        $this->assertTrue($target->refresh()->revoked);
        $this->bearer($issued['access_token'])->assertUnauthorized();
        $this->assertTrue(Token::query()->findOrFail('synthetic-second-user-token')->revoked);
        $this->assertFalse(Token::query()->findOrFail('synthetic-unrelated-user-token')->revoked);
        $this->refresh($target, $issued['refresh_token'])->assertUnauthorized();
        $this->exchange($target, $pendingCode)->assertUnauthorized();
        $codeCount = AuthCode::query()->count();
        $this->authorize($user, $target, [AgentApiScopes::IDENTITY_READ])->assertHeaderMissing('Location');
        $this->assertSame($codeCount, AuthCode::query()->count());
        $this->assertSame(0, Token::query()->where('client_id', $target->id)->where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::query()->where('client_id', $target->id)->where('revoked', false)->count());
        $this->assertSame(0, AuthCode::query()->where('client_id', $target->id)->where('revoked', false)->count());
        $this->assertSame(0, OAuthTokenFamily::query()->where('client_id', $target->id)->where('revoked', false)->count());
        $this->assertSame(0, OAuthClientConsent::query()->where('client_id', $target->id)->whereNull('revoked_at')->count());
        $this->bearer($unrelated['access_token'])->assertOk();
        $this->assertFalse($other->refresh()->revoked);
        $this->assertNull(OAuthClientConsent::query()->where('client_id', $other->id)->sole()->revoked_at);
        $this->assertSame(0, Artisan::call('phr:agent-api:revoke-first-party-client', ['client_id' => $target->id]));
        $this->assertSame(2, Client::query()->count(), 'Registrations remain as audit records.');
    }

    public function test_interrupted_cleanup_keeps_the_registration_disabled_and_can_resume(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $issued = $this->exchange($client, $this->approve())->assertOk()->json();
        $interruptOnce = true;
        OAuthClientConsent::updating(static function () use (&$interruptOnce): void {
            if ($interruptOnce) {
                $interruptOnce = false;
                throw new \RuntimeException('Synthetic cleanup interruption');
            }
        });

        try {
            Artisan::call('phr:agent-api:revoke-first-party-client', ['client_id' => $client->id]);
            $this->fail('Cleanup must propagate its interruption.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic cleanup interruption', $exception->getMessage());
        }
        $this->assertTrue($client->refresh()->revoked);
        $this->assertSame(1, Token::query()->where('client_id', $client->id)->where('revoked', false)->count());
        // A new HTTP request has no Passport once() cache from issuance.
        Once::flush();
        $this->bearer($issued['access_token'])->assertUnauthorized();
        $this->refresh($client, $issued['refresh_token'])->assertUnauthorized();
        $this->assertSame(0, Artisan::call('phr:agent-api:revoke-first-party-client', ['client_id' => $client->id]));
        $this->assertSame(0, Token::query()->where('client_id', $client->id)->where('revoked', false)->count());
    }

    public function test_orphaned_refresh_credentials_are_found_by_their_client_and_owner(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $refresh = RefreshToken::query()->forceCreate([
            'id' => 'synthetic-orphan-refresh', 'access_token_id' => 'synthetic-purged-token',
            'client_id' => $client->id, 'provider_user_id' => (string) $user->id,
            'revoked' => false, 'expires_at' => now()->addDays(30),
        ]);
        $this->assertSame(0, Artisan::call('phr:agent-api:revoke-first-party-client', ['client_id' => $client->id]));
        $this->assertTrue($refresh->refresh()->revoked);
    }

    public function test_ineligible_registrations_are_refused(): void
    {
        $repository = app(ClientRepository::class);
        $owner = $this->createUser();
        $clients = [
            $repository->createAuthorizationCodeGrantClient('Synthetic Public', [self::CALLBACK], false, null),
            $repository->createClientCredentialsGrantClient('Synthetic Machine'),
            $repository->createAuthorizationCodeGrantClient('Synthetic Owned', [self::CALLBACK], true, $owner),
            $this->firstPartyClient(name: 'Synthetic Dynamic'),
        ];
        $clients[3]->forceFill(['dynamically_registered_at' => now()])->save();
        foreach ($clients as $client) {
            $this->assertSame(1, Artisan::call('phr:agent-api:revoke-first-party-client', ['client_id' => $client->id]));
            $this->assertFalse($client->refresh()->revoked);
        }
        $this->assertSame(1, Artisan::call('phr:agent-api:revoke-first-party-client', ['client_id' => 'synthetic-missing-client']));
    }

    /**
     * @param  list<string>  $scopes
     */
    private function firstPartyClient(string $name = 'Synthetic Finance', bool $allowListed = true, array $scopes = [AgentApiScopes::IDENTITY_READ]): Client
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, [self::CALLBACK], true, null);
        $client->forceFill(['scopes' => $scopes])->save();
        $secret = $client->plainSecret;
        if ($allowListed) {
            config(['agent_api.first_party_oauth_clients' => [
                ...config('agent_api.first_party_oauth_clients', []),
                (string) $client->id,
            ]]);
        }
        $client = Client::query()->findOrFail($client->id);
        $client->plainSecret = $secret;

        return $client;
    }

    /** @param  list<string>  $scopes */
    private function authorize(User $user, Client $client, array $scopes, ?string $resource = null): TestResponse
    {
        Once::flush();
        Auth::forgetGuards();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');

        return $this->withoutToken()->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query(array_filter([
            'client_id' => $client->id,
            'redirect_uri' => self::CALLBACK,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'synthetic-consent-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => $resource,
        ])));
    }

    private function approve(): string
    {
        return $this->codeFrom($this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect());
    }

    private function codeFrom(TestResponse $redirect): string
    {
        $location = (string) $redirect->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('synthetic-consent-state', $query['state'] ?? null);
        $this->assertSame(url('/'), $query['iss'] ?? null, 'RFC 9207 issuer on every authorization response');
        $this->assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function exchange(Client $client, string $code): TestResponse
    {
        Once::flush();
        Auth::forgetGuards();

        return $this->withoutToken()->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri' => self::CALLBACK,
            'code_verifier' => self::VERIFIER,
            'code' => $code,
        ]);
    }

    private function refresh(Client $client, string $refreshToken): TestResponse
    {
        Once::flush();
        Auth::forgetGuards();

        return $this->withoutToken()->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'refresh_token' => $refreshToken,
        ]);
    }

    private function bearer(string $accessToken): TestResponse
    {
        Once::flush();
        Auth::forgetGuards();

        return $this->withToken($accessToken)->getJson('/api/v1/me');
    }

}
