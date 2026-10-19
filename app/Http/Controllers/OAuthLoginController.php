<?php

namespace App\Http\Controllers;

use App\Models\User;
use BWH\Auth\Concerns\LogsAuthEvents;
use BWH\Auth\Concerns\SignsOutThroughProvider;
use BWH\Auth\OAuth\OAuthClient;
use BWH\Auth\OAuth\ProviderApplications;
use BWH\Auth\OAuth\Session\ProviderSession;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OAuthLoginController extends Controller
{
    use LogsAuthEvents;
    use SignsOutThroughProvider;

    /**
     * The identity provider is PHR's only sign-in method, so a person sent here
     * to sign in (Passport's authorization endpoint, or any page that needs a
     * session) goes straight on to it: a silent single sign-on round trip must
     * not stop at a page with one link. A bare visit, with nowhere to return
     * to, still renders the page; so does any configuration that offers other
     * sign-in methods or has no provider client.
     */
    public function login(Request $request): RedirectResponse|View
    {
        if ($request->hasSession()
            && $request->session()->has('url.intended')
            && $this->identityProviderIsTheOnlySignInMethod()) {
            return redirect()->route('oauth.redirect');
        }

        return view('login');
    }

    public function redirect(Request $request, OAuthClient $oauth): RedirectResponse
    {
        return $oauth->redirect($request);
    }

    public function callback(Request $request, OAuthClient $oauth, ProviderSession $providerSession): RedirectResponse
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

        // Record this login's provider generation, the baseline enforcement
        // checks the session against. With enforcement off this never fails
        // the sign-in; with it on, a login that cannot be verified is undone.
        try {
            $providerSession->establish($request, $identity, Auth::guard());
        } catch (ProviderStatusUnavailable) {
            $this->auditLoginFailed($request, $user, $identity->email, 'Provider session unverified', 'oauth');

            abort(503, 'Sign-in verification is unavailable. Please retry.');
        }

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

    private function identityProviderIsTheOnlySignInMethod(): bool
    {
        $clientId = config('bherila-auth.oauth_client.client_id');
        $providerUrl = config('bherila-auth.oauth_client.base_url');

        return is_string($clientId) && $clientId !== ''
            && is_string($providerUrl) && $providerUrl !== ''
            && ! (bool) config('bherila-auth.routes.enabled', false);
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
