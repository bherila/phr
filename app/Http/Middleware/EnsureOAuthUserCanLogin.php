<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOAuthUserCanLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if (! $user instanceof User || ! $user->canLogin()) {
            $user?->revokeOAuthTokens();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
