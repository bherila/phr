<?php

namespace Tests\Feature;

use App\Models\PhrPatient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.identity_provider', [
            'name' => 'bherila',
            'base_url' => 'https://identity.example.test',
            'client_id' => 'phr-client',
            'client_secret' => 'phr-secret',
            'redirect_uri' => 'http://localhost/oauth/callback',
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

    /**
     * @return array<string, string>
     */
    private function oauthSession(): array
    {
        return [
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => str_repeat('v', 64),
        ];
    }

    private function fakeProvider(string $subject, string $name, string $email): void
    {
        Http::fake(function (Request $request) use ($subject, $name, $email) {
            if ($request->url() === 'https://identity.example.test/oauth/token') {
                $this->assertSame('authorization_code', $request['grant_type']);
                $this->assertSame('authorization-code', $request['code']);
                $this->assertSame(str_repeat('v', 64), $request['code_verifier']);

                return Http::response(['access_token' => 'test-access-token'], 200);
            }

            if ($request->url() === 'https://identity.example.test/api/oauth/user') {
                $this->assertSame('Bearer test-access-token', $request->header('Authorization')[0] ?? null);

                return Http::response(['sub' => $subject, 'name' => $name, 'email' => $email], 200);
            }

            return Http::response([], 404);
        });
    }
}
