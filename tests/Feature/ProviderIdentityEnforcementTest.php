<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

/**
 * The auth package's provider identity enforcement, wired behind
 * BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED. Off (the default), sign-in, browser
 * sessions and OAuth credentials behave exactly as before, apart from the
 * baseline being recorded. On, a session or credential whose identity ended at
 * the provider is refused.
 */
class ProviderIdentityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const string SUBJECT = 'provider-subject-77';

    private const string STATUS_URL = 'https://identity.example.test/api/reconciliation/identity-status';

    private const string REDIRECT_URI = 'https://agent.example.test/callback';

    /** What the provider's login identity response reports as the generation. */
    private ?int $loginGeneration = 3;

    /** What the provider's status endpoint answers: a generation, or null for inactive. */
    private ?int $statusGeneration = 3;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('bherila-auth.oauth_client', [
            'provider' => 'bherila',
            'base_url' => 'https://identity.example.test',
            'client_id' => 'phr-client',
            'client_secret' => 'phr-secret',
            'redirect_uri' => 'http://localhost/oauth/callback',
            'scope' => 'identity:read',
            'authorize_path' => '/oauth/authorize',
            'token_path' => '/oauth/token',
            'identity_path' => '/api/oauth/user',
            'end_session_path' => '/oauth/end-session',
        ]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $details['key']]);

        Http::fake(function (HttpRequest $request) {
            return match ($request->url()) {
                'https://identity.example.test/oauth/token' => Http::response(['access_token' => 'test-access-token']),
                'https://identity.example.test/api/oauth/user' => Http::response(array_filter([
                    'sub' => self::SUBJECT,
                    'name' => 'Synthetic Bound User',
                    'email' => 'bound-user@example.test',
                    'credential_version' => $this->loginGeneration,
                ], static fn (mixed $value): bool => $value !== null)),
                self::STATUS_URL => Http::response($this->statusGeneration === null
                    ? ['contract_version' => 1, 'active' => false]
                    : ['contract_version' => 1, 'active' => true, 'subject' => self::SUBJECT, 'credential_version' => $this->statusGeneration]),
                default => Http::response([], 404),
            };
        });
    }

    public function test_enforcement_is_off_by_default(): void
    {
        $this->assertFalse(config('bherila-auth.provider_identity.enabled'));
        $this->assertFalse(ProviderIdentityTokens::enabled());
    }

    public function test_with_enforcement_off_sign_in_and_sessions_are_unchanged(): void
    {
        // A provider that reports no generation still signs people in.
        $this->loginGeneration = null;
        $this->signIn()->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertNull(session('bherila_auth.provider_session'));

        $this->get('/phr/patients')->assertOk();
        $this->getJson('/api/phr/patients')->assertOk();
        // A session that predates the baseline is not checked either.
        $this->actingAs($this->boundUser())->get('/phr/patients')->assertOk();
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::STATUS_URL);
    }

    public function test_with_enforcement_off_sign_in_records_the_baseline(): void
    {
        $this->signIn()->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertSame(self::SUBJECT, session('bherila_auth.provider_session.subject'));
        $this->assertSame(3, session('bherila_auth.provider_session.generation'));
    }

    public function test_with_enforcement_off_unstamped_oauth_credentials_keep_working(): void
    {
        $user = $this->boundUser();
        $client = $this->client();

        $issued = $this->authorizeAndExchange($user, $client);
        $token = Token::query()->where('user_id', $user->id)->sole();
        $this->assertNull($token->getAttribute(ProviderIdentityTokens::GENERATION_COLUMN));

        Auth::forgetGuards();
        $this->withToken($issued['access_token'])->getJson('/api/v1/me')->assertOk();
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $issued['refresh_token'],
        ])->assertOk();
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->url() === self::STATUS_URL);
    }

    public function test_with_enforcement_on_a_session_without_a_baseline_is_ended(): void
    {
        $this->enableEnforcement();
        $user = $this->boundUser();

        $this->actingAs($user)->getJson('/api/phr/patients')->assertUnauthorized();
        $this->assertGuest();
        $this->actingAs($user)->get('/phr/patients')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_with_enforcement_on_sign_in_needs_a_generation(): void
    {
        $this->enableEnforcement();
        $this->loginGeneration = null;

        $this->signIn()->assertStatus(503);
        $this->assertGuest();
    }

    public function test_with_enforcement_on_a_session_ends_with_its_identity(): void
    {
        $this->enableEnforcement();
        $this->signIn()->assertRedirect('/');
        $this->get('/phr/patients')->assertOk();
        $this->getJson('/api/phr/patients')->assertOk();

        // Disabled at the provider: once the shared observation is stale, the
        // next request ends the session.
        $this->statusGeneration = null;
        $this->travel(301)->seconds();
        $this->get('/phr/patients')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_with_enforcement_on_a_reset_at_the_provider_ends_the_session_on_the_next_write(): void
    {
        $this->enableEnforcement();
        $this->signIn()->assertRedirect('/');
        $this->getJson('/api/phr/patients')->assertOk();

        // A password reset at the provider moves the generation on. Unsafe
        // requests check freshly, without waiting out the freshness window.
        $this->statusGeneration = 4;
        $this->getJson('/api/phr/patients')->assertOk();
        $this->postJson('/api/phr/patients', ['display_name' => 'Synthetic'])->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_with_enforcement_on_oauth_credentials_carry_the_session_stamp_and_end_with_the_identity(): void
    {
        $this->enableEnforcement();
        $this->signIn()->assertRedirect('/');
        $user = User::query()->where('oauth_subject', self::SUBJECT)->sole();
        $client = $this->client();

        $issued = $this->authorizeAndExchange($user, $client, signedIn: true);
        foreach ([AuthCode::query()->sole(), Token::query()->where('user_id', $user->id)->sole(), RefreshToken::query()->sole()] as $credential) {
            $this->assertSame(self::SUBJECT, $credential->getAttribute(ProviderIdentityTokens::SUBJECT_COLUMN));
            $this->assertSame(3, (int) $credential->getAttribute(ProviderIdentityTokens::GENERATION_COLUMN));
        }
        Auth::forgetGuards();
        $this->withToken($issued['access_token'])->getJson('/api/v1/me')->assertOk();
        $rotated = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $issued['refresh_token'],
        ])->assertOk()->json();
        $this->assertSame(
            [3, 3],
            Token::query()->where('user_id', $user->id)->pluck(ProviderIdentityTokens::GENERATION_COLUMN)->map(fn ($value): int => (int) $value)->all(),
        );

        // Disabled at the provider: bearer use and renewal are both refused.
        $this->statusGeneration = null;
        Cache::flush();
        Auth::forgetGuards();
        $this->withToken($rotated['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $rotated['refresh_token'],
        ])->assertBadRequest();
        // Refused, not consumed: only the refresh token rotated earlier is revoked.
        $this->assertSame(1, RefreshToken::query()->where('revoked', true)->count());
    }

    public function test_with_enforcement_on_credentials_issued_before_it_are_refused(): void
    {
        $user = $this->boundUser();
        $client = $this->client();
        $issued = $this->authorizeAndExchange($user, $client);

        $this->enableEnforcement();
        Auth::forgetGuards();
        $this->withToken($issued['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $issued['refresh_token'],
        ])->assertBadRequest();
    }

    private function enableEnforcement(): void
    {
        config(['bherila-auth.provider_identity.enabled' => true]);
    }

    private function signIn(): TestResponse
    {
        return $this->withSession([
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => str_repeat('v', 64),
        ])->get('/oauth/callback?state=expected-state&code=authorization-code');
    }

    private function boundUser(): User
    {
        $user = User::query()->where('oauth_subject', self::SUBJECT)->first() ?? User::factory()->create([
            'email' => 'bound-user@example.test',
            'user_role' => 'user',
        ]);
        $user->forceFill(['oauth_provider' => 'bherila', 'oauth_subject' => self::SUBJECT])->save();

        return $user;
    }

    private function client(): Client
    {
        return Client::query()->create([
            'name' => 'Synthetic Stamp Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => [self::REDIRECT_URI],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
    }

    /**
     * Consent in the browser session and exchange the code. `signedIn` keeps the
     * session established by signIn(); otherwise the user acts without a baseline.
     *
     * @return array{access_token: string, refresh_token: string}
     */
    private function authorizeAndExchange(User $user, Client $client, bool $signedIn = false): array
    {
        $verifier = str_repeat('stamp-verifier-', 4);
        $request = $signedIn ? $this : $this->actingAs($user);
        $request->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-stamp-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))->assertOk();
        $approval = $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null);

        $issued = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
            'code' => $query['code'],
        ])->assertOk()->json();
        $this->assertIsString($issued['access_token'] ?? null);
        $this->assertIsString($issued['refresh_token'] ?? null);

        return $issued;
    }
}
