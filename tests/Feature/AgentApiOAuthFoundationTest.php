<?php

namespace Tests\Feature;

use App\Http\Middleware\SerializeOAuthTokenExchange;
use App\Models\AgentApiAudit;
use App\Models\User;
use App\Support\AgentApi\AccountAwareAuthCodeRepository;
use App\Support\AgentApi\AccountAwareRefreshTokenRepository;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Client;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

class AgentApiOAuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CI intentionally has no production signing keys. Ephemeral in-memory keys
        // exercise Passport without writing credentials into the repository tree.
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

    public function test_oauth_metadata_advertises_authorization_code_pkce_and_rotating_refresh_tokens(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
            ->assertJsonPath('response_types_supported', ['code'])
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('scopes_supported', AgentApiScopes::ids());

        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/v1'))
            ->assertJsonPath('authorization_servers.0', url('/'));
        $this->getJson('/.well-known/oauth-protected-resource/api/v1')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/v1'));

        $this->assertTrue(Passport::$revokeRefreshTokenAfterUse);
        $this->assertFalse(Passport::$implicitGrantEnabled);
        $this->assertFalse(Passport::$passwordGrantEnabled);
        $this->assertFalse(Passport::$deviceCodeGrantEnabled);
        $this->assertGreaterThanOrEqual(895, $this->intervalSeconds(Passport::tokensExpireIn()));
        $this->assertLessThanOrEqual(900, $this->intervalSeconds(Passport::tokensExpireIn()));
        $this->assertGreaterThanOrEqual(2_591_995, $this->intervalSeconds(Passport::refreshTokensExpireIn()));
        $this->assertLessThanOrEqual(2_592_000, $this->intervalSeconds(Passport::refreshTokensExpireIn()));
    }

    public function test_authorization_endpoint_rejects_missing_or_downgraded_pkce_without_redirecting(): void
    {
        $query = http_build_query([
            'client_id' => 'caller-controlled-client',
            'redirect_uri' => 'https://untrusted.example.test/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
        ]);

        $this->getJson('/oauth/authorize?'.$query)
            ->assertBadRequest()
            ->assertHeaderMissing('Location')
            ->assertJson([
                'error' => 'invalid_request',
                'error_description' => 'Authorization requests require S256 PKCE.',
            ]);

        $this->getJson('/oauth/authorize?'.$query.'&code_challenge=synthetic&code_challenge_method=plain')
            ->assertBadRequest()
            ->assertHeaderMissing('Location');
    }

    public function test_public_client_completes_pkce_refresh_rotation_and_immediate_revocation(): void
    {
        $user = $this->createUser([
            'name' => 'Synthetic OAuth User',
            'email' => 'oauth-user@example.test',
        ]);
        $client = Client::query()->create([
            'name' => 'Synthetic Public Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $verifier = str_repeat('synthetic-verifier-', 3);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $authorization = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]));
        $authorization->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
            ->assertSee('Synthetic Public Client');

        $approval = $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
        ])->assertRedirect()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);
        $this->assertSame('synthetic-state', $redirectQuery['state']);
        $this->assertIsString($redirectQuery['code']);

        $issued = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://client.example.test/callback',
            'code_verifier' => $verifier,
            'code' => $redirectQuery['code'],
        ])->assertOk()->json();
        $this->assertSame(900, $issued['expires_in']);
        $originalAccessToken = Token::query()->where('user_id', $user->id)->sole();
        $originalRefreshToken = RefreshToken::query()->where('access_token_id', $originalAccessToken->id)->sole();

        $this->withToken($issued['access_token'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('identity.email', 'oauth-user@example.test');

        $rotated = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $issued['refresh_token'],
        ])->assertOk()->json();
        $this->assertNotSame($issued['refresh_token'], $rotated['refresh_token']);
        $this->assertTrue($originalAccessToken->fresh()->revoked);
        $this->assertTrue($originalRefreshToken->fresh()->revoked);

        Auth::forgetGuards();
        $this->withToken($issued['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($rotated['access_token'])->deleteJson('/api/v1/oauth/token')->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($rotated['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_capabilities_and_openapi_are_versioned_and_share_the_scope_contract(): void
    {
        $capabilities = $this->getJson('/api/v1/capabilities')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('api_version', 'v1')
            ->assertJsonPath('limits.maximum_page_size', 100)
            ->assertJsonPath('oauth.authorization_code_pkce', true)
            ->json();

        $document = json_decode((string) file_get_contents(public_path('openapi/phr-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(
            AgentApiScopes::ids(),
            array_keys($document['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']),
        );
        $this->assertSame(
            AgentApiScopes::ids(),
            array_keys($capabilities['scopes']),
        );
        $this->assertSame(
            ['capabilities.get', 'identity.get', 'oauth.disconnect'],
            collect($document['paths'])->flatMap(fn (array $path): array => array_column($path, 'operationId'))->values()->all(),
        );
        $this->assertSame(
            [AgentApiScopes::IDENTITY_READ],
            $document['paths']['/me']['get']['security'][0]['oauth2'],
        );
        $this->assertSame(
            [],
            $document['paths']['/oauth/token']['delete']['security'][0]['oauth2'],
        );
        $this->assertSame(
            url('/.well-known/oauth-protected-resource/api/v1'),
            $capabilities['oauth']['protected_resource_metadata'],
        );
    }

    public function test_identity_requires_the_exact_scope_and_never_allows_caching(): void
    {
        $user = $this->createUser([
            'name' => 'Synthetic Agent User',
            'email' => 'agent-user@example.test',
        ]);

        Passport::actingAs($user, [AgentApiScopes::PATIENTS_READ]);
        $this->getJson('/api/v1/me')->assertForbidden();

        Passport::actingAs($user, [AgentApiScopes::IDENTITY_READ]);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('identity.id', $user->id)
            ->assertJsonPath('identity.email', 'agent-user@example.test')
            ->assertJsonPath('scopes.0', AgentApiScopes::IDENTITY_READ);
    }

    public function test_unauthenticated_agent_request_is_json_and_is_not_audited_as_an_actor(): void
    {
        $this->get('/api/v1/me', ['Accept' => 'text/html'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertDatabaseCount('agent_api_audits', 0);
    }

    public function test_authenticated_requests_have_metadata_only_audits_even_when_scope_is_denied(): void
    {
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Test Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);

        Passport::actingAs($user, [AgentApiScopes::PATIENTS_READ], 'api', $client);
        $this->withHeader('Authorization', 'Bearer should-never-be-persisted')
            ->getJson('/api/v1/me?clinical_value=should-never-be-persisted')
            ->assertForbidden();

        $audit = AgentApiAudit::query()->sole();
        $this->assertSame(403, $audit->response_status);
        $this->assertSame('agent-api.v1.me', $audit->route_name);
        $this->assertSame($client->id, $audit->oauth_client_id);
        $this->assertSame([
            'id',
            'request_id',
            'actor_user_id',
            'oauth_client_id',
            'oauth_token_hash',
            'event',
            'route_name',
            'http_method',
            'response_status',
            'duration_ms',
            'created_at',
        ], Schema::getColumnListing('agent_api_audits'));
        $this->assertStringNotContainsString(
            'should-never-be-persisted',
            json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_disconnect_immediately_revokes_the_access_and_refresh_token_family(): void
    {
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Disconnect Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $tokenId = Str::random(80);
        $token = Token::query()->create([
            'id' => $tokenId,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => null,
            'scopes' => [],
            'revoked' => false,
            'expires_at' => now()->addMinutes(15),
        ]);
        $refresh = RefreshToken::query()->create([
            'id' => Str::random(80),
            'access_token_id' => $tokenId,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);
        Passport::actingAs($user, [], 'api', $client);
        $user->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $tokenId,
            'oauth_client_id' => $client->id,
            'oauth_user_id' => (string) $user->id,
            'oauth_scopes' => [],
        ]));
        Auth::guard('api')->setUser($user);

        $this->deleteJson('/api/v1/oauth/token')->assertNoContent();

        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($refresh->fresh()->revoked);
        $this->assertSame(hash('sha256', $tokenId), AgentApiAudit::query()->sole()->oauth_token_hash);
    }

    public function test_agent_api_is_rate_limited_per_authenticated_user(): void
    {
        Passport::actingAs($this->createUser(), [AgentApiScopes::IDENTITY_READ]);

        for ($request = 1; $request <= 120; $request++) {
            $this->getJson('/api/v1/me')->assertOk();
        }

        for ($request = 1; $request <= 11; $request++) {
            $this->getJson('/api/v1/me')->assertTooManyRequests();
        }
        $this->assertDatabaseCount('agent_api_audits', 121);
        $this->assertDatabaseHas('agent_api_audits', [
            'route_name' => 'agent-api.v1.me',
            'response_status' => 429,
        ]);
    }

    public function test_saturated_read_limit_does_not_block_self_revocation(): void
    {
        Passport::actingAs($this->createUser(), [AgentApiScopes::IDENTITY_READ]);

        for ($request = 1; $request <= 120; $request++) {
            $this->getJson('/api/v1/me')->assertOk();
        }

        $this->getJson('/api/v1/me')->assertTooManyRequests();
        $this->deleteJson('/api/v1/oauth/token')->assertNoContent();
    }

    public function test_disabled_accounts_cannot_use_bearer_or_refresh_tokens(): void
    {
        // User id 1 is intentionally always an administrator and cannot be disabled.
        $this->createAdminUser();
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Disabled Account Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $token = Token::query()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => null,
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false,
            'expires_at' => now()->addMinutes(15),
        ]);
        $refresh = RefreshToken::query()->create([
            'id' => Str::random(80),
            'access_token_id' => $token->id,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);

        Passport::actingAs($user, [AgentApiScopes::IDENTITY_READ], 'api', $client);
        $this->getJson('/api/v1/me')->assertOk();

        $user->forceFill(['user_role' => ''])->save();
        $this->assertFalse($user->canLogin());
        $this->assertSame(1, Passport::token()->newQuery()->where('user_id', $user->id)->count());
        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($refresh->fresh()->revoked);

        Passport::actingAs($user->fresh(), [AgentApiScopes::IDENTITY_READ], 'api', $client);
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertDatabaseHas('agent_api_audits', [
            'actor_user_id' => $user->id,
            'route_name' => 'agent-api.v1.me',
            'response_status' => 401,
        ]);
    }

    public function test_refresh_repository_fails_closed_when_account_was_disabled_outside_eloquent(): void
    {
        // User id 1 is intentionally always an administrator and cannot be disabled.
        $this->createAdminUser();
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Refresh Guard Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $token = Token::query()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => null,
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false,
            'expires_at' => now()->addMinutes(15),
        ]);
        $refresh = RefreshToken::query()->create([
            'id' => Str::random(80),
            'access_token_id' => $token->id,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);
        $otherToken = Token::query()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => null,
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false,
            'expires_at' => now()->addMinutes(15),
        ]);
        $otherRefresh = RefreshToken::query()->create([
            'id' => Str::random(80),
            'access_token_id' => $otherToken->id,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);

        DB::table('users')->where('id', $user->id)->update(['user_role' => '']);
        $this->assertFalse(User::query()->findOrFail($user->id)->canLogin());
        $this->assertSame($user->id, $token->fresh()->user_id);

        $repository = app(PassportRefreshTokenRepository::class);
        $this->assertInstanceOf(AccountAwareRefreshTokenRepository::class, $repository);
        $this->assertTrue($repository->isRefreshTokenRevoked($refresh->id));
        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($refresh->fresh()->revoked);
        $this->assertTrue($otherToken->fresh()->revoked);
        $this->assertTrue($otherRefresh->fresh()->revoked);
    }

    public function test_account_lifecycle_revokes_pending_authorization_codes_without_access_tokens(): void
    {
        // User id 1 is intentionally always an administrator and cannot be disabled.
        $this->createAdminUser();
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Pending Authorization Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code'],
            'revoked' => false,
        ]);
        $authorizationCode = AuthCode::query()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => json_encode([AgentApiScopes::IDENTITY_READ], JSON_THROW_ON_ERROR),
            'revoked' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        $user->forceFill(['user_role' => ''])->save();

        $this->assertTrue($authorizationCode->fresh()->revoked);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_token_exchange_is_serialized_and_rechecks_account_state_after_issuance(): void
    {
        $route = Route::getRoutes()->getByName('passport.token');
        $this->assertNotNull($route);
        $this->assertContains(SerializeOAuthTokenExchange::class, $route->gatherMiddleware());

        $this->createAdminUser();
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Post-Issuance Guard Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $verifier = str_repeat('post-issuance-check-', 3);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://client.example.test/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-post-issuance-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk();
        $approval = $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'),
        ])->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);
        $this->assertIsString($redirectQuery['code']);

        // Disable the account immediately after Passport persists the new
        // refresh token but before the exchange middleware returns its response.
        $passportConnection = DB::connection(is_string(config('passport.connection')) ? config('passport.connection') : null);
        $baselineTransactionLevel = $passportConnection->transactionLevel();
        $issuanceTransactionLevel = $baselineTransactionLevel;
        Event::listen(RefreshTokenCreated::class, function () use ($user, $passportConnection, &$issuanceTransactionLevel): void {
            $issuanceTransactionLevel = $passportConnection->transactionLevel();
            DB::table('users')->where('id', $user->id)->update(['user_role' => '']);
        });
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => 'https://client.example.test/callback',
            'code_verifier' => $verifier,
            'code' => $redirectQuery['code'],
        ])->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        $this->assertGreaterThan($baselineTransactionLevel, $issuanceTransactionLevel);
        $token = Token::query()->where('user_id', $user->id)->sole();
        $refresh = RefreshToken::query()->where('access_token_id', $token->id)->sole();
        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($refresh->fresh()->revoked);
    }

    public function test_auth_code_repository_fails_closed_when_account_was_disabled_outside_eloquent(): void
    {
        // User id 1 is intentionally always an administrator and cannot be disabled.
        $this->createAdminUser();
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Authorization Guard Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code'],
            'revoked' => false,
        ]);
        $authorizationCode = AuthCode::query()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => json_encode([AgentApiScopes::IDENTITY_READ], JSON_THROW_ON_ERROR),
            'revoked' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        DB::table('users')->where('id', $user->id)->update(['user_role' => '']);

        $repository = app(PassportAuthCodeRepository::class);
        $this->assertInstanceOf(AccountAwareAuthCodeRepository::class, $repository);
        $this->assertTrue($repository->isAuthCodeRevoked($authorizationCode->id));
        $this->assertTrue($authorizationCode->fresh()->revoked);
    }

    public function test_account_lifecycle_revocation_uses_passports_configured_connection(): void
    {
        $connection = config('database.connections.sqlite');
        $this->assertIsArray($connection);
        config([
            'database.connections.passport_test' => [...$connection, 'database' => ':memory:'],
            'passport.connection' => 'passport_test',
        ]);
        $schema = Schema::connection('passport_test');
        $schema->create('oauth_access_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });
        $schema->create('oauth_refresh_tokens', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->char('access_token_id', 80)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
        $schema->create('oauth_auth_codes', function (Blueprint $table): void {
            $table->char('id', 80)->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->uuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        $user = $this->createUser();
        $token = Passport::token()->newQuery()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => false,
            'expires_at' => now()->addMinutes(15),
        ]);
        $refresh = Passport::refreshToken()->newQuery()->create([
            'id' => Str::random(80),
            'access_token_id' => $token->id,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);
        $authorizationCode = Passport::authCode()->newQuery()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $token->client_id,
            'scopes' => json_encode([AgentApiScopes::IDENTITY_READ], JSON_THROW_ON_ERROR),
            'revoked' => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        $user->revokeOAuthTokens();

        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue($refresh->fresh()->revoked);
        $this->assertTrue($authorizationCode->fresh()->revoked);
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $token->id, 'revoked' => true], 'passport_test');
        $this->assertDatabaseHas('oauth_refresh_tokens', ['id' => $refresh->id, 'revoked' => true], 'passport_test');
        $this->assertDatabaseHas('oauth_auth_codes', ['id' => $authorizationCode->id, 'revoked' => true], 'passport_test');
    }

    public function test_agent_api_audits_are_pruned_on_the_configured_retention_window(): void
    {
        config(['agent_api.audit_retention_days' => 30]);
        AgentApiAudit::query()->create($this->auditAttributes(now()->subDays(31)));
        $recent = AgentApiAudit::query()->create($this->auditAttributes(now()->subDays(29)));

        $this->artisan('phr:agent-api:prune-audits')->assertSuccessful();

        $this->assertDatabaseCount('agent_api_audits', 1);
        $this->assertDatabaseHas('agent_api_audits', ['id' => $recent->id]);
    }

    public function test_scheduled_passport_purge_removes_revoked_credentials(): void
    {
        $user = $this->createUser();
        $client = Client::query()->create([
            'name' => 'Synthetic Purge Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => ['https://client.example.test/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        $token = Token::query()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => null,
            'scopes' => [AgentApiScopes::IDENTITY_READ],
            'revoked' => true,
            'expires_at' => now()->addMinutes(15),
        ]);
        $refresh = RefreshToken::query()->create([
            'id' => Str::random(80),
            'access_token_id' => $token->id,
            'revoked' => true,
            'expires_at' => now()->addDays(30),
        ]);
        $authorizationCode = AuthCode::query()->create([
            'id' => Str::random(80),
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => json_encode([AgentApiScopes::IDENTITY_READ], JSON_THROW_ON_ERROR),
            'revoked' => true,
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->artisan('phr:uptime:run-task', ['job' => 'passport:purge'])->assertSuccessful();

        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseMissing('oauth_refresh_tokens', ['id' => $refresh->id]);
        $this->assertDatabaseMissing('oauth_auth_codes', ['id' => $authorizationCode->id]);
        $this->assertDatabaseHas('uptime_runs', [
            'job_name' => 'passport:purge',
            'status' => 'success',
            'exit_code' => 0,
        ]);
    }

    public function test_oauth_key_verifier_accepts_only_a_parseable_matching_pair(): void
    {
        $directory = storage_path('framework/testing/oauth-'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        Passport::loadKeysFrom($directory);

        [$privateKey, $publicKey] = $this->keyPair();
        file_put_contents($directory.'/oauth-private.key', $privateKey);
        file_put_contents($directory.'/oauth-public.key', $publicKey);
        $this->artisan('phr:agent-api:verify-oauth-keys')->assertSuccessful();

        [, $otherPublicKey] = $this->keyPair();
        file_put_contents($directory.'/oauth-public.key', $otherPublicKey);
        $this->artisan('phr:agent-api:verify-oauth-keys')->assertFailed();

        file_put_contents($directory.'/oauth-private.key', 'invalid synthetic key');
        $this->artisan('phr:agent-api:verify-oauth-keys')->assertFailed();

        File::deleteDirectory($directory);
        Passport::loadKeysFrom(storage_path('app/private/oauth'));
    }

    /** @return array<string, mixed> */
    private function auditAttributes(\DateTimeInterface $createdAt): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'actor_user_id' => null,
            'oauth_client_id' => null,
            'oauth_token_hash' => null,
            'event' => 'agent_api.request',
            'route_name' => 'agent-api.v1.me',
            'http_method' => 'GET',
            'response_status' => 200,
            'duration_ms' => 1,
            'created_at' => $createdAt,
        ];
    }

    /** @return array{string, string} */
    private function keyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        return [$privateKey, $details['key']];
    }

    private function intervalSeconds(\DateInterval $interval): int
    {
        $origin = new \DateTimeImmutable('@0');

        return $origin->add($interval)->getTimestamp();
    }
}
