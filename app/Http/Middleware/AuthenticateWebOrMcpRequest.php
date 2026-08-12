<?php

namespace App\Http\Middleware;

use App\Models\PhrDeviceKey;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWebOrMcpRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        $token = $this->extractBearerToken($request);

        if ($token !== null) {
            $hash = User::hashMcpToken($token);

            $user = User::query()
                ->where('mcp_api_key', $hash)
                ->first();

            // mcpTokenIsActive() fails closed: a token with no recorded expiry
            // is rejected rather than treated as eternal.
            if ($user !== null && $user->canLogin() && $user->mcpTokenIsActive()) {
                $user->recordMcpTokenUse();
                Auth::setUser($user);

                return $next($request);
            }

            // The legacy per-user key missed; try a per-device key minted by
            // the device-pairing flow (DevicePairingExchangeController). Same
            // fail-closed shape as above: isActive() rejects a revoked or
            // expired key rather than treating a missing state as valid.
            $deviceKey = PhrDeviceKey::query()
                ->with('user')
                ->where('token_hash', $hash)
                ->first();

            if ($deviceKey !== null && $deviceKey->isActive() && $deviceKey->user?->canLogin()) {
                $deviceKey->recordUse();
                Auth::setUser($deviceKey->user);

                return $next($request);
            }
        }

        // Deliberately identical for an unknown, revoked and expired token, so
        // the response does not confirm that a token was ever valid.
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return null;
    }
}
