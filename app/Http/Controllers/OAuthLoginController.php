<?php

namespace App\Http\Controllers;

use App\Models\User;
use BWH\Auth\Concerns\LogsAuthEvents;
use BWH\Auth\Concerns\SignsOutThroughProvider;
use BWH\Auth\OAuth\OAuthClient;
use BWH\Auth\OAuth\ProviderApplications;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OAuthLoginController extends Controller
{
    use LogsAuthEvents;
    use SignsOutThroughProvider;

    public function redirect(Request $request, OAuthClient $oauth): RedirectResponse
    {
        return $oauth->redirect($request);
    }

    public function callback(Request $request, OAuthClient $oauth): RedirectResponse
    {
        $identity = $oauth->identityFromCallback($request);

        $user = $this->resolveUser(
            provider: $identity->provider,
            subject: $identity->subject,
            name: $identity->name,
            email: $identity->email,
        );

        /*
         * The removed password login rejected accounts whose roles were stripped
         * (User::canLogin); the provider cannot know about local role state, so
         * the same gate must run here or disabling an account stops working the
         * moment sign-in moves to OAuth.
         */
        if (! $user->canLogin()) {
            $this->auditLoginFailed($request, $user, $identity->email, 'Account disabled', 'oauth');

            abort(403, 'This account is disabled.');
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Cached for the session rather than fetched per request: this is navigation chrome,
        // and the callback is the only moment an access token for the provider is in hand.
        // Keeping it server-side also keeps the list of the other applications out of the
        // JS bundle, so what exists is not readable by anyone who downloads it.
        ProviderApplications::remember($request, $identity->apps);

        $this->auditLoginSucceeded($request, $user, 'oauth');

        return redirect()->intended('/');
    }

    public function logout(Request $request, OAuthClient $oauth): RedirectResponse
    {
        return $this->signOutThroughProvider($request, $oauth);
    }

    protected function afterLocalSignOut(Request $request, ?Authenticatable $user): void
    {
        $this->auditLoggedOut($request, $user);
    }

    private function resolveUser(string $provider, string $subject, string $name, string $email): User
    {
        try {
            return DB::transaction(function () use ($provider, $subject, $name, $email): User {
                $user = User::query()
                    ->where('oauth_provider', $provider)
                    ->where('oauth_subject', $subject)
                    ->lockForUpdate()
                    ->first();

                if ($user === null) {
                    return User::query()->forceCreate([
                        'name' => $name,
                        'email' => $email,
                        'email_verified_at' => now(),
                        'password' => Hash::make(Str::random(64)),
                        'user_role' => 'user',
                        'oauth_provider' => $provider,
                        'oauth_subject' => $subject,
                    ]);
                }

                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'email_verified_at' => now(),
                ])->save();

                return $user;
            });
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw $exception;
            }

            $user = User::query()
                ->where('oauth_provider', $provider)
                ->where('oauth_subject', $subject)
                ->first();

            abort_if(
                $user === null
                || $user->name !== $name
                || strcasecmp($user->email, $email) !== 0,
                409,
                'The provider identity could not be provisioned.',
            );

            return $user;
        }
    }
}
