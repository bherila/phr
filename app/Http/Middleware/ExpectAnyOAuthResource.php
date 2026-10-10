<?php

namespace App\Http\Middleware;

use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept a credential bound to any one of the named protected resources, for the
 * few REST routes an MCP tool hands to its client to call directly with the
 * connection's own credential (file downloads, DICOM file uploads, the GenAI
 * queue, self-revocation).
 *
 * Only the listed names can be selected: the one the bearer token claims, or else
 * the first. Passport's resource server then verifies the signature, the stored
 * binding, the audience and the scope ceiling against exactly that resource, so a
 * forged or unlisted claim fails as on any single-resource route. An
 * unauthenticated request is challenged with the first resource's metadata.
 * Register before `auth:api`, as with ExpectOAuthResource.
 */
final class ExpectAnyOAuthResource
{
    public function handle(Request $request, Closure $next, string ...$resources): Response
    {
        $expected = $resources[0] ?? null;
        $claimed = $this->claimedResource($request);
        if ($claimed !== null && in_array($claimed, $resources, true)) {
            $expected = $claimed;
        }
        OAuthResourceIndicator::expectFor($request, $expected);

        return $next($request);
    }

    /** The configured resource name the bearer token claims, unverified; null when none. */
    private function claimedResource(Request $request): ?string
    {
        $token = $request->bearerToken();
        $claims = is_string($token) ? OAuthResourceIndicator::tokenClaims($token) : null;
        if ($claims === null) {
            return null;
        }

        $audiences = $claims['aud'] ?? [];
        $candidates = [$claims['resource'] ?? null, ...(is_array($audiences) ? $audiences : [$audiences])];
        foreach ($candidates as $candidate) {
            $name = OAuthResourceIndicator::nameFor($candidate);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }
}
