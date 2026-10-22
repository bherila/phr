<?php

namespace Tests\Feature;

use App\Models\OAuthClientConsent;
use App\Models\OAuthTokenFamily;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

final class UserConnectedAppsTest extends TestCase
{
    use RefreshDatabase;

    private const string CALLBACK = 'https://synthetic.example.test/oauth/callback';

    private const string VERIFIER = 'synthetic-connected-app-verifier-synthetic-connected-app-verifier';

    public function test_management_requires_a_browser_session(): void
    {
        $this->getJson('/api/user/connected-apps')->assertUnauthorized();
        $this->deleteJson('/api/user/connected-apps/synthetic-client')->assertUnauthorized();
    }

    public function test_listing_groups_active_resource_consents_and_exposes_only_display_fields(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $client = $this->client('Synthetic connected app');
        $this->consent($user, (string) $client->id);
        $this->consent($user, (string) $client->id, url('/api/v1/mcp'), [AgentApiScopes::MCP_USE]);
        $this->consent($other, (string) $client->id, 'https://other-user.example.test/resource');
        $this->consent($user, (string) $this->client('Revoked app')->id)->update(['revoked_at' => now()]);
        $orphanId = (string) Str::uuid();
        $this->consent($user, $orphanId);

        $response = $this->actingAs($user, 'web')->getJson('/api/user/connected-apps?user_id='.$other->id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('meta.total', 2);
        $data = collect($response->json('data'))->keyBy('client_id');
        $this->assertSame('Synthetic connected app', $data[(string) $client->id]['name']);
        $this->assertSame('Unavailable application', $data[$orphanId]['name']);
        $this->assertSame(['client_id', 'name', 'resources'], array_keys($data[(string) $client->id]));
        $resources = $data[(string) $client->id]['resources'];
        $this->assertCount(2, $resources);
        $this->assertSame([url('/api/v1'), url('/api/v1/mcp')], array_column($resources, 'resource'));
        $this->assertSame([AgentApiScopes::IDENTITY_READ], $resources[0]['scopes']);
        $this->assertSame([AgentApiScopes::MCP_USE], $resources[1]['scopes']);
        $this->assertSame(['resource', 'scopes', 'granted_at'], array_keys($resources[0]));
        $this->assertNotNull($resources[0]['granted_at']);
    }

    public function test_pagination_counts_clients_instead_of_resource_grants(): void
    {
        $user = $this->createUser();
        $ids = [];
        for ($i = 0; $i < 22; $i++) {
            $ids[] = $id = (string) $this->client('Synthetic app '.$i)->id;
            $this->consent($user, $id);
            $this->consent($user, $id, url('/api/v1/mcp'), [AgentApiScopes::MCP_USE]);
        }
        sort($ids);
        $first = $this->actingAs($user, 'web')->getJson('/api/user/connected-apps')
            ->assertOk()->assertJsonPath('meta', [
                'current_page' => 1, 'last_page' => 2, 'per_page' => 20, 'total' => 22,
            ]);
        $second = $this->getJson('/api/user/connected-apps?page=2')
            ->assertOk()->assertJsonPath('meta.current_page', 2);
        $this->assertSame(array_slice($ids, 0, 20), array_column($first->json('data'), 'client_id'));
        $this->assertSame(array_slice($ids, 20), array_column($second->json('data'), 'client_id'));
        $this->getJson('/api/user/connected-apps?page=0')->assertUnprocessable();
        $this->getJson('/api/user/connected-apps?page=3')->assertOk()->assertJsonPath('data', []);
    }

    public function test_disconnect_is_user_scoped_idempotent_and_handles_deleted_clients(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $id = (string) $this->client()->id;
        $otherConsent = $this->consent($other, $id);
        $this->actingAs($user, 'web')->deleteJson('/api/user/connected-apps/'.$id, ['user_id' => $other->id])
            ->assertNotFound();
        $this->assertNull($otherConsent->fresh()->revoked_at);
        $this->deleteJson('/api/user/connected-apps/'.Str::uuid())->assertNotFound();

        $owned = $this->consent($user, $id);
        $this->deleteJson('/api/user/connected-apps/'.$id, ['user_id' => $other->id])
            ->assertNoContent()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertNotNull($owned->fresh()->revoked_at);
        $this->assertNull($otherConsent->fresh()->revoked_at);
        $this->deleteJson('/api/user/connected-apps/'.$id)->assertNoContent();
        $this->getJson('/api/user/connected-apps')->assertOk()->assertJsonPath('data', []);

        $orphanId = (string) Str::uuid();
        $orphan = $this->consent($user, $orphanId);
        $this->deleteJson('/api/user/connected-apps/'.$orphanId)->assertNoContent();
        $this->assertNotNull($orphan->fresh()->revoked_at);
    }

    public function test_disconnect_requires_the_session_csrf_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $user = $this->createUser();
        $id = (string) $this->client()->id;
        $consent = $this->consent($user, $id);
        $this->actingAs($user, 'web')->deleteJson('/api/user/connected-apps/'.$id)->assertStatus(419);
        $this->assertNull($consent->fresh()->revoked_at);
        $this->withSession(['_token' => 'synthetic-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'synthetic-csrf-token')
            ->deleteJson('/api/user/connected-apps/'.$id)->assertNoContent();
    }

    public function test_disconnect_retires_all_resource_credentials_and_reconnect_requires_consent(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $details['key']]);
        $user = $this->createUser();
        $client = $this->client();
        $other = $this->client('Synthetic untouched app');
        config(['agent_api.first_party_oauth_clients' => [(string) $client->id, (string) $other->id]]);

        $this->authorize($user, $client)->assertOk();
        $rest = $this->exchange($client, $this->approve())->assertOk()->json();
        $this->authorize($user, $client, mcp: true)->assertOk();
        $mcp = $this->exchange($client, $this->approve())->assertOk()->json();
        $pending = $this->codeFrom($this->authorize($user, $client)->assertRedirect());
        $this->authorize($user, $other)->assertOk();
        $untouched = $this->exchange($other, $this->approve())->assertOk()->json();

        Auth::forgetGuards();
        $this->withToken($rest['access_token'])->getJson('/api/user/connected-apps')->assertUnauthorized();
        $this->deleteJson('/api/user/connected-apps/'.$client->id)->assertUnauthorized();
        $this->withoutToken()->actingAs($user, 'web')
            ->deleteJson('/api/user/connected-apps/'.$client->id)->assertNoContent();
        $this->assertSame(2, OAuthClientConsent::query()->where('client_id', $client->id)->whereNotNull('revoked_at')->count());
        $this->assertSame(0, AuthCode::query()->where('client_id', $client->id)->where('revoked', false)->count());
        $this->assertSame(0, OAuthTokenFamily::query()->where('client_id', $client->id)->where('revoked', false)->count());
        $this->assertSame(0, Token::query()->where('client_id', $client->id)->where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::query()->where('client_id', $client->id)->where('revoked', false)->count());
        $this->bearer($rest['access_token'])->assertUnauthorized();
        $this->bearer($mcp['access_token'])->assertUnauthorized();
        $this->refresh($client, $rest['refresh_token'])->assertBadRequest();
        $this->refresh($client, $mcp['refresh_token'])->assertBadRequest();
        $this->exchange($client, $pending)->assertBadRequest();
        $this->bearer($untouched['access_token'])->assertOk();
        $this->authorize($user, $client)->assertOk()->assertSee('Synthetic connected app');
        $renewed = $this->exchange($client, $this->approve())->assertOk()->json();
        $this->bearer($renewed['access_token'])->assertOk();
    }

    private function client(string $name = 'Synthetic connected app'): Client
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, [self::CALLBACK], true, null);
        $client->forceFill(['scopes' => [AgentApiScopes::IDENTITY_READ, AgentApiScopes::MCP_USE]])->save();

        return $client;
    }

    /** @param list<string> $scopes */
    private function consent(User $user, string $id, ?string $resource = null, array $scopes = [AgentApiScopes::IDENTITY_READ]): OAuthClientConsent
    {
        return OAuthClientConsent::query()->create([
            'user_id' => $user->id,
            'client_id' => $id,
            'resource' => $resource ?? url('/api/v1'),
            'scopes' => $scopes,
            'version' => 1,
            'granted_at' => now(),
        ]);
    }

    private function authorize(User $user, Client $client, bool $mcp = false): TestResponse
    {
        Auth::forgetGuards();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');

        return $this->withoutToken()->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => self::CALLBACK,
            'response_type' => 'code',
            'scope' => $mcp ? AgentApiScopes::IDENTITY_READ.' '.AgentApiScopes::MCP_USE : AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-connected-app-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => url($mcp ? '/api/v1/mcp' : '/api/v1'),
        ]));
    }

    private function approve(): string
    {
        return $this->codeFrom($this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect());
    }

    private function codeFrom(TestResponse $redirect): string
    {
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function exchange(Client $client, string $code): TestResponse
    {
        Auth::forgetGuards();

        return $this->withoutToken()->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'redirect_uri' => self::CALLBACK,
            'code_verifier' => self::VERIFIER, 'code' => $code,
        ]);
    }

    private function refresh(Client $client, string $refreshToken): TestResponse
    {
        Auth::forgetGuards();

        return $this->withoutToken()->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'refresh_token' => $refreshToken,
        ]);
    }

    private function bearer(string $accessToken): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($accessToken)->getJson('/api/v1/me');
    }
}
