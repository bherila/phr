<?php

namespace Tests\Feature;

use App\Models\PhrPatient;
use App\Models\User;
use BWH\Auth\OAuth\ProviderApplications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthLoginTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_sign_in_click_starts_authorization_code_flow_with_pkce(): void
    {
        $response = $this->get('/oauth/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://identity.example.test/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame('identity:read', $query['scope'] ?? null);
        $this->assertArrayNotHasKey('prompt', $query);
        $this->assertSame(session('oauth.login.state'), $query['state'] ?? null);
        $this->assertNotSame('', session('oauth.login.code_verifier'));
    }

    public function test_callback_resolves_by_subject_and_refreshes_provider_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old-address@example.test',
        ]);
        $user->forceFill([
            'oauth_provider' => 'bherila',
            'oauth_subject' => 'provider-subject-42',
        ])->save();

        $this->fakeProvider('provider-subject-42', 'Updated Name', 'updated-address@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'oauth_provider' => 'bherila',
            'oauth_subject' => 'provider-subject-42',
            'name' => 'Updated Name',
            'email' => 'updated-address@example.test',
        ]);
    }

    public function test_first_login_creates_an_empty_account_without_patient_access(): void
    {
        $owner = User::factory()->create();
        PhrPatient::query()->create([
            'owner_user_id' => $owner->getKey(),
            'display_name' => 'Existing patient',
        ]);
        $this->fakeProvider('new-provider-subject', 'New Account', 'new-account@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertRedirect('/');

        $newUser = User::query()
            ->where('oauth_provider', 'bherila')
            ->where('oauth_subject', 'new-provider-subject')
            ->sole();

        $this->assertAuthenticatedAs($newUser);
        $this->assertSame(0, PhrPatient::query()->accessibleBy((int) $newUser->getKey())->count());
    }

    public function test_auto_provisioned_account_gets_no_data_from_clinical_endpoints(): void
    {
        $owner = User::factory()->create();
        $patient = PhrPatient::query()->create([
            'owner_user_id' => $owner->getKey(),
            'display_name' => 'Existing patient',
        ]);
        $this->fakeProvider('new-provider-subject', 'New Account', 'new-account@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertRedirect('/');

        $this->getJson('/api/phr/patients')
            ->assertOk()
            ->assertExactJson(['patients' => []]);
        $this->getJson("/api/phr/patients/{$patient->id}")->assertNotFound();
        $this->getJson("/api/phr/patients/{$patient->id}/documents")->assertNotFound();
        $this->getJson("/api/phr/patients/{$patient->id}/dicom/studies")->assertNotFound();
        $this->get("/phr/patient/{$patient->id}")->assertNotFound();
    }

    public function test_disabled_account_cannot_sign_in_through_the_provider(): void
    {
        User::factory()->create();
        $disabledUser = User::factory()->create(['user_role' => '']);
        $disabledUser->forceFill([
            'oauth_provider' => 'bherila',
            'oauth_subject' => 'disabled-provider-subject',
        ])->save();
        $this->assertFalse($disabledUser->canLogin());

        $this->fakeProvider('disabled-provider-subject', 'Disabled Account', 'disabled-account@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => 'login_failed',
            'auth_method' => 'oauth',
            'user_id' => $disabledUser->getKey(),
            'reason' => 'Account disabled',
        ]);
    }

    public function test_oauth_sign_in_is_audited(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'oauth_provider' => 'bherila',
            'oauth_subject' => 'audited-provider-subject',
        ])->save();
        $this->fakeProvider('audited-provider-subject', 'Audited Name', 'audited-address@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertRedirect('/');

        $this->assertDatabaseHas('auth_audit_log', [
            'event' => 'login_succeeded',
            'auth_method' => 'oauth',
            'user_id' => $user->getKey(),
        ]);

        $this->post('/logout')->assertRedirect(
            'https://identity.example.test/oauth/end-session?client_id=phr-client&post_logout_redirect_uri=http%3A%2F%2Flocalhost',
        );
        $this->assertDatabaseHas('auth_audit_log', [
            'event' => 'logged_out',
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_matching_email_never_rebinds_a_different_account(): void
    {
        $existingUser = User::factory()->create(['email' => 'reused-address@example.test']);
        $this->fakeProvider('different-provider-subject', 'Different Account', 'reused-address@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertConflict();

        $this->assertGuest();
        $this->assertNull($existingUser->fresh()?->oauth_subject);
        $this->assertDatabaseMissing('users', [
            'oauth_provider' => 'bherila',
            'oauth_subject' => 'different-provider-subject',
        ]);
    }

    public function test_bound_account_is_not_logged_in_with_a_stale_profile_when_email_refresh_conflicts(): void
    {
        $boundUser = User::factory()->create(['email' => 'old-bound-address@example.test']);
        $boundUser->forceFill([
            'oauth_provider' => 'bherila',
            'oauth_subject' => 'bound-provider-subject',
        ])->save();
        User::factory()->create(['email' => 'claimed-address@example.test']);
        $this->fakeProvider('bound-provider-subject', 'Updated Name', 'claimed-address@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertConflict();

        $this->assertGuest();
        $this->assertSame('old-bound-address@example.test', $boundUser->fresh()?->email);
    }

    public function test_state_mismatch_is_rejected_before_contacting_the_provider(): void
    {
        Http::fake();

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=wrong-state&code=authorization-code')
            ->assertForbidden();

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_rejected_authorization_code_does_not_create_or_authenticate_an_account(): void
    {
        Http::fake([
            'https://identity.example.test/oauth/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=rejected-code')
            ->assertStatus(502);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, string>
     */
    public function test_signing_out_ends_the_session_at_the_provider(): void
    {
        $user = $this->boundUser('signed-out-subject');
        $this->fakeProvider('signed-out-subject', 'Signed Out', 'signed-out@example.test');

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code');

        $response = $this->post('/logout');

        // Signing out only here would leave the provider still recognising this person, so
        // the next protected page would hand them back without a prompt.
        $response->assertRedirect(
            'https://identity.example.test/oauth/end-session?client_id=phr-client&post_logout_redirect_uri=http%3A%2F%2Flocalhost',
        );
        $this->assertGuest();
    }

    public function test_signing_out_stays_local_when_no_provider_is_configured(): void
    {
        Config::set('bherila-auth.oauth_client.client_id', '');

        $user = User::factory()->create();

        // Handing off to a provider that was never configured aborts 503, which would make
        // signing out fail outright — worse than signing out only locally.
        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_sign_in_caches_the_provider_application_list_for_the_session(): void
    {
        $this->boundUser('app-list-subject');
        $this->fakeProvider('app-list-subject', 'App List', 'app-list@example.test', [
            ['key' => 'games', 'name' => 'Games', 'url' => 'https://games.example.test'],
        ]);

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code')
            ->assertRedirect('/');

        $this->assertSame(
            [['key' => 'games', 'name' => 'Games', 'url' => 'https://games.example.test']],
            session(ProviderApplications::SESSION_KEY),
        );
    }

    public function test_the_application_list_reaches_the_page_but_never_an_anonymous_one(): void
    {
        $this->boundUser('rendered-subject');
        $this->fakeProvider('rendered-subject', 'Rendered', 'rendered@example.test', [
            ['key' => 'games', 'name' => 'Games', 'url' => 'https://games.example.test'],
        ]);

        $this->withSession($this->oauthSession())
            ->get('/oauth/callback?state=expected-state&code=authorization-code');

        $this->get('/phr/patients')->assertSee('https://games.example.test');

        // The list is chrome for someone who is signed in. The sign-in page is rendered from
        // the same layout, so an ungated injection would publish which applications exist to
        // anyone who merely loads it.
        Auth::logout();
        $this->get('/login')->assertOk()->assertDontSee('https://games.example.test');
    }

    private function boundUser(string $subject): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'oauth_provider' => 'bherila',
            'oauth_subject' => $subject,
        ])->save();

        return $user;
    }

    private function oauthSession(): array
    {
        return [
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => str_repeat('v', 64),
        ];
    }

    /**
     * @param  list<array<string, string>>  $apps
     */
    private function fakeProvider(string $subject, string $name, string $email, array $apps = []): void
    {
        Http::fake(function (Request $request) use ($subject, $name, $email, $apps) {
            if ($request->url() === 'https://identity.example.test/oauth/token') {
                $this->assertSame('authorization_code', $request['grant_type']);
                $this->assertSame('authorization-code', $request['code']);
                $this->assertSame(str_repeat('v', 64), $request['code_verifier']);

                return Http::response(['access_token' => 'test-access-token'], 200);
            }

            if ($request->url() === 'https://identity.example.test/api/oauth/user') {
                $this->assertSame('Bearer test-access-token', $request->header('Authorization')[0] ?? null);

                return Http::response(['sub' => $subject, 'name' => $name, 'email' => $email, 'apps' => $apps], 200);
            }

            return Http::response([], 404);
        });
    }
}
