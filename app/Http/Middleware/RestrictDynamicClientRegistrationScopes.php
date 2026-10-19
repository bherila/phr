<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\AgentApiScopes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-registration validates the requested scopes against the authorization
 * server's catalog, and an omitted scope registers the whole catalog. A
 * first-party-only scope (billing:read) must be neither: for the duration of
 * the registration request the catalog it sees leaves those scopes out, so
 * asking for one is invalid_client_metadata and the default ceiling omits
 * it. The authorization endpoint then refuses it for that client too, since
 * it holds a dynamic client to its registered scopes.
 */
final class RestrictDynamicClientRegistrationScopes
{
    private const string CATALOG = 'bherila-auth.oauth_server.scopes';

    public function handle(Request $request, Closure $next): Response
    {
        $catalog = config(self::CATALOG);
        if (! is_array($catalog)) {
            return $next($request);
        }
        $restricted = AgentApiScopes::firstPartyOnlyIds();
        config([self::CATALOG => array_is_list($catalog)
            ? array_values(array_diff($catalog, $restricted))
            : array_diff_key($catalog, array_flip($restricted))]);
        try {
            return $next($request);
        } finally {
            config([self::CATALOG => $catalog]);
        }
    }
}
