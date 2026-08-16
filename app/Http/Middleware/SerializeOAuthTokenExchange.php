<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\OAuthExchangeAccountGuard;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SerializeOAuthTokenExchange
{
    public function __construct(private OAuthExchangeAccountGuard $accountGuard) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.token')) {
            return $next($request);
        }

        $connection = config('passport.connection');
        $response = DB::connection(is_string($connection) ? $connection : null)
            ->transaction(fn (): Response => $next($request), 1);

        $accountIsActive = $this->accountGuard->credentialsMayBeReturned();
        if (! $accountIsActive && $response->isSuccessful()) {
            return new JsonResponse([
                'error' => 'invalid_grant',
                'error_description' => 'The authorization grant is invalid.',
            ], 400, [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
            ]);
        }

        return $response;
    }
}
