<?php

namespace Tests\Feature;

use App\Models\OAuthClientConsent;
use App\Models\OAuthTokenFamily;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthCredentialRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * Durable OAuth consent (G1) through Passport's real authorization, approval,
 * token and protected-resource routes: a first-party client consents once and
 * then re-authorizes silently; revocation, scope expansion and a revocation
 * racing an exchange or refresh all end with no usable credential.
 */
final class AgentApiOAuthConsentTest extends TestCase
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

    public function test_first_party_client_consents_once_then_reauthorizes_without_a_prompt(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();

        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])
            ->assertOk()
            ->assertSee('Synthetic Finance');
        $code = $this->approve();

        $consent = OAuthClientConsent::query()->sole();
        $this->assertSame($user->id, $consent->user_id);
        $this->assertSame((string) $client->id, $consent->client_id);
        $this->assertSame(url('/api/v1'), $consent->resource);
        $this->assertSame([AgentApiScopes::IDENTITY_READ], $consent->scopes);
        $this->assertSame(1, $consent->version);
        $this->assertNull($consent->revoked_at);
        $this->assertSame(1, (int) AuthCode::query()->sole()->oauth_consent_version);

        $issued = $this->exchange($client, $code)->assertOk()->json();
        $token = Token::query()->sole();
        $this->assertSame(1, (int) $token->oauth_consent_version);
        $this->assertSame(1, OAuthTokenFamily::query()->sole()->oauth_consent_version);
        $this->bearer($issued['access_token'])->assertOk();

        // Long after every access token expired, a new authorization round trip
        // is approved without the consent screen.
        $this->travel(2)->hours();
        $silent = $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ]);
        $silent->assertRedirect();
        $code = $this->codeFrom($silent);
        $this->exchange($client, $code)->assertOk();
        $this->assertSame(1, OAuthClientConsent::query()->sole()->version);
    }

    public function test_a_client_that_is_not_allow_listed_shows_the_consent_screen_every_time_tokens_lapse(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient(allowListed: false);

        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $this->exchange($client, $this->approve())->assertOk();
        $this->assertNotNull(OAuthClientConsent::query()->sole());

        $this->travel(2)->hours();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])
            ->assertOk()
            ->assertSee('Synthetic Finance');
    }

    public function test_scope_expansion_and_another_resource_show_the_consent_screen_again(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient(scopes: [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ]);

        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $this->exchange($client, $this->approve())->assertOk();

        // A request within the consent is silent; a wider one asks again.
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertRedirect();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ])
            ->assertOk()
            ->assertSee('Synthetic Finance');
        $this->exchange($client, $this->approve())->assertOk();

        $consent = OAuthClientConsent::query()->sole();
        $this->assertSame([AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ], $consent->scopes);
        $this->assertSame(1, $consent->version);
        $this->authorize($user, $client, [AgentApiScopes::PATIENTS_READ])->assertRedirect();

        // Consent is per protected resource: the same client asking for the MCP
        // resource is a new consent.
        DB::table('oauth_clients')->where('id', $client->id)->update(['scopes' => json_encode([
            AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::MCP_USE,
        ])]);
        $this->authorize($user, $client, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ], url('/api/v1/mcp'))
            ->assertOk()
            ->assertSee('Synthetic Finance');
    }

    public function test_a_code_or_refresh_token_from_before_a_revoke_and_regrant_is_refused(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();

        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $issued = $this->exchange($client, $this->approve())->assertOk()->json();
        $staleCode = $this->codeFrom($this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertRedirect());

        // Revoked and granted again elsewhere, without this family having been
        // swept (as if it escaped the sweep): the version fence alone refuses it.
        OAuthClientConsent::query()->sole()->forceFill(['version' => 2])->save();

        $this->exchange($client, $staleCode)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertTrue(AuthCode::query()->where('revoked', false)->doesntExist());
        $this->refresh($client, $issued['refresh_token'])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertTrue(OAuthTokenFamily::query()->sole()->revoked);
        $this->bearer($issued['access_token'])->assertUnauthorized();
    }

    public function test_active_tokens_never_skip_the_consent_screen_once_consent_is_revoked(): void
    {
        $user = $this->createUser();
        // Third-party: Passport would approve silently on its unexpired tokens.
        $client = $this->firstPartyClient(allowListed: false);

        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $this->exchange($client, $this->approve())->assertOk();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertRedirect();

        // Consent withdrawn without the token sweep: the live access token must
        // not stand in for consent.
        OAuthClientConsent::query()->sole()->forceFill(['revoked_at' => now(), 'version' => 2])->save();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk()->assertSee('Synthetic Finance');
    }

    public function test_consent_revoked_while_a_code_exchange_is_in_flight_yields_no_usable_token(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $code = $this->approve();

        // The revocation lands after the code was validated and while the new
        // family is being issued.
        $this->onceWhileIssuing(fn () => app(OAuthCredentialRevoker::class)->revokeClientConsent($user->id, (string) $client->id));

        $response = $this->exchange($client, $code);

        $this->assertNoUsableCredential($response, $client);
    }

    public function test_an_exchange_family_that_escaped_the_revocation_sweep_is_retired_after_commit(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $code = $this->approve();

        // Only the consent changes mid-exchange: the revoker's sweep is modelled
        // as having run before this family existed. The post-exchange recheck
        // must catch it.
        $this->onceWhileIssuing(fn () => DB::table('oauth_client_consents')->update(['revoked_at' => now(), 'version' => 2]));

        $response = $this->exchange($client, $code);

        $this->assertNoUsableCredential($response, $client);
        $this->assertTrue(OAuthTokenFamily::query()->sole()->revoked);
    }

    public function test_consent_revoked_while_a_refresh_is_in_flight_yields_no_usable_token(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $issued = $this->exchange($client, $this->approve())->assertOk()->json();

        $this->onceWhileIssuing(fn () => app(OAuthCredentialRevoker::class)->revokeClientConsent($user->id, (string) $client->id));
        $response = $this->refresh($client, $issued['refresh_token']);

        $this->assertNoUsableCredential($response, $client);
        $this->bearer($issued['access_token'])->assertUnauthorized();
    }

    public function test_a_refresh_family_that_escaped_the_revocation_sweep_is_retired_after_commit(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $issued = $this->exchange($client, $this->approve())->assertOk()->json();

        $this->onceWhileIssuing(fn () => DB::table('oauth_client_consents')->update(['revoked_at' => now(), 'version' => 2]));
        $response = $this->refresh($client, $issued['refresh_token']);

        $this->assertNoUsableCredential($response, $client);
        $this->assertTrue(OAuthTokenFamily::query()->sole()->revoked);
    }

    public function test_a_code_issued_without_consent_can_never_be_exchanged(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $code = $this->approve();
        // A code row predating consent tracking, or written by any other path.
        AuthCode::query()->update(['oauth_consent_version' => null]);

        $this->exchange($client, $code)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, Token::query()->count());
    }

    public function test_legacy_authorization_code_families_are_adopted_as_consented(): void
    {
        $user = $this->createUser();
        $client = $this->firstPartyClient();
        $this->authorize($user, $client, [AgentApiScopes::IDENTITY_READ])->assertOk();
        $issued = $this->exchange($client, $this->approve())->assertOk()->json();

        // As the credentials looked before consent was recorded.
        OAuthClientConsent::query()->delete();
        DB::table('oauth_token_families')->update(['oauth_consent_version' => null]);
        DB::table('oauth_access_tokens')->update(['oauth_consent_version' => null]);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Synthetic Personal Access', 'users');
        $personal = $user->createToken('Synthetic personal token', [AgentApiScopes::IDENTITY_READ]);

        $migration = require database_path('migrations/2026_10_19_000000_create_oauth_client_consents.php');
        $migration->up();

        $consent = OAuthClientConsent::query()->sole();
        $this->assertSame((string) $client->id, $consent->client_id);
        $this->assertSame(url('/api/v1'), $consent->resource);
        $this->assertSame([AgentApiScopes::IDENTITY_READ], $consent->scopes);
        $this->assertSame(1, $consent->version);
        $this->assertNull(Token::query()->whereKey($personal->token->id)->value('oauth_consent_version'));

        $this->refresh($client, $issued['refresh_token'])->assertOk();
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
        Auth::forgetGuards();

        return $this->withToken($accessToken)->getJson('/api/v1/me');
    }

    /** Run $action once, inside the token request, after the new credentials are written. */
    private function onceWhileIssuing(callable $action): void
    {
        $ran = false;
        Event::listen(RefreshTokenCreated::class, function () use (&$ran, $action): void {
            if ($ran) {
                return;
            }
            $ran = true;
            $action();
        });
    }

    private function assertNoUsableCredential(TestResponse $response, Client $client): void
    {
        $response->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, Token::query()->where('client_id', $client->id)->where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::query()->where('client_id', $client->id)->where('revoked', false)->count());
        $this->assertSame(0, OAuthTokenFamily::query()->where('client_id', $client->id)->where('revoked', false)->count());
    }
}
