<?php

namespace App\Models;

use App\Support\AgentApi\FirstPartyOAuthClients;
use App\Support\AgentApi\OAuthClientConsents;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ResourceClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Scope;

/**
 * PHR's Passport client model. It keeps the auth package's resource handling
 * (dynamically registered clients are never first-party and never skip
 * consent) and lets exactly one kind of client skip the consent screen: an
 * allow-listed first-party application whose person has already approved, in
 * a consent that is still in force, every scope it now asks for on the same
 * protected resource. Everything else shows the consent screen.
 *
 * Registered with Passport::useClientModel() during registration, before the
 * auth package boots; the package only swaps in its own model while Passport's
 * base model is still in place.
 */
final class PassportClient extends ResourceClient
{
    /** @param  array<int, Scope>  $scopes */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        if (! app(FirstPartyOAuthClients::class)->allows($this)) {
            return false;
        }

        return app(OAuthClientConsents::class)->covering(
            $user->getAuthIdentifier(),
            (string) $this->getKey(),
            OAuthResourceIndicator::validatedFor(request()),
            array_values(array_map(static fn (Scope $scope): string => $scope->id, $scopes)),
        ) !== null;
    }
}
