<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOAuthAuthorizationUserCanLogin
{
    public function __construct(private Factory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.authorizations.*')) {
            return $next($request);
        }

        $guard = $this->auth->guard((string) config('passport.guard', 'web'));
        if (! $guard instanceof StatefulGuard) {
            return $this->deniedResponse();
        }

        $authenticated = $guard->user();
        if ($authenticated === null) {
            return $next($request);
        }

        $user = User::query()->find($authenticated->getAuthIdentifier());
        if ($user instanceof User && $user->canLogin()) {
            return $next($request);
        }

        $user?->revokeOAuthTokens();
        $guard->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->deniedResponse();
    }

    private function deniedResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'access_denied',
            'error_description' => 'Authorization is not available for this account.',
        ], 403, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ]);
    }
}
