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
        $isTokenExchange = $request->routeIs('passport.token');
        $isAuthorizationApproval = $request->routeIs('passport.authorizations.approve');
        if (! $isTokenExchange && ! $isAuthorizationApproval) {
            return $next($request);
        }

        $connection = config('passport.connection');
        $response = DB::connection(is_string($connection) ? $connection : null)
            ->transaction(fn (): Response => $next($request), 1);

        if (! $isTokenExchange) {
            return $response;
        }

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

        $patient = $request->attributes->get(OAuthExchangeAccountGuard::PATIENT_RESPONSE_ATTRIBUTE);
        $request->attributes->remove(OAuthExchangeAccountGuard::PATIENT_RESPONSE_ATTRIBUTE);
        if ($accountIsActive && $response->isSuccessful() && is_string($patient)) {
            $body = json_decode((string) $response->getContent(), true);
            if (is_array($body)) {
                $response->setContent(json_encode([...$body, 'patient' => $patient], JSON_THROW_ON_ERROR));
            }
        }

        return $response;
    }
}
