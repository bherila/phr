<?php

namespace App\Http\Controllers;

use BWH\Auth\Http\Controllers\RelyingPartyLoginController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sign-in and sign-out through the identity provider. The shared controller does the work;
 * the account rules are in App\Auth\OAuthAccounts, and the disabled-account gate is
 * User::canLogin(), reached through the package's AuthUserPolicy.
 */
class OAuthLoginController extends RelyingPartyLoginController
{
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

    private function identityProviderIsTheOnlySignInMethod(): bool
    {
        $clientId = config('bherila-auth.oauth_client.client_id');
        $providerUrl = config('bherila-auth.oauth_client.base_url');

        return is_string($clientId) && $clientId !== ''
            && is_string($providerUrl) && $providerUrl !== ''
            && ! (bool) config('bherila-auth.routes.enabled', false);
    }
}
