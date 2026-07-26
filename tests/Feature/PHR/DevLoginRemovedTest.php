<?php

namespace Tests\Feature\PHR;

use App\Http\Controllers\LoginController;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression: the dev-login escape hatches must stay deleted.
 *
 * `POST /login/dev` and `POST /login/dev-by-id` authenticated the caller as
 * any user with no password, and `login()` accepted a hardcoded master
 * password. All three were gated only by `isLocalhost()`, which trusted
 * `APP_ENV`/`APP_URL` — so a single misconfigured environment variable turned
 * them into a public login-as-anyone endpoint. The repository is public, so
 * these must not come back as config-gated routes.
 */
class DevLoginRemovedTest extends TestCase
{
    public function test_dev_login_routes_are_not_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->all();

        $this->assertNotContains('login.dev', $names);
        $this->assertNotContains('login.dev.by-id', $names);

        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        $this->assertNotContains('login/dev', $uris);
        $this->assertNotContains('login/dev-by-id', $uris);
    }

    public function test_dev_login_endpoints_return_not_found(): void
    {
        $user = $this->createUser();

        $this->post('/login/dev', ['email' => $user->email])->assertNotFound();
        $this->post('/login/dev-by-id', ['user_id' => $user->id])->assertNotFound();

        $this->assertGuest();
    }

    public function test_login_controller_exposes_no_dev_methods(): void
    {
        $methods = get_class_methods(LoginController::class);

        $this->assertNotContains('devLogin', $methods);
        $this->assertNotContains('devLoginById', $methods);
    }

    public function test_local_password_login_is_not_registered(): void
    {
        $user = $this->createUser(['password' => 'a-real-and-distinct-password']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => '1234567890',
        ])->assertMethodNotAllowed();

        $this->assertGuest();
    }

    public function test_correct_local_password_cannot_bypass_the_identity_provider(): void
    {
        $user = $this->createUser(['password' => 'a-real-and-distinct-password']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'a-real-and-distinct-password',
        ])->assertMethodNotAllowed();

        $this->assertGuest();
    }

    public function test_set_password_command_updates_credentials(): void
    {
        $user = $this->createUser(['password' => 'original-password']);

        $this->artisan('user:set-password', [
            'email' => $user->email,
            '--password' => 'replacement-password',
        ])->assertExitCode(0);

        $this->assertTrue(Hash::check(
            'replacement-password',
            User::query()->findOrFail($user->id)->password,
        ));
    }

    public function test_set_password_command_fails_for_unknown_user(): void
    {
        $this->artisan('user:set-password', ['email' => 'nobody@example.test'])
            ->assertExitCode(1);
    }
}
