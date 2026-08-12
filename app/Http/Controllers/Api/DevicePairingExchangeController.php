<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DevicePairingExchangeRequest;
use App\Models\PhrDeviceKey;
use App\Models\PhrDevicePairingCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Exchanges a one-time device-pairing code (minted by
 * DevicePairingController::approve()) for a per-device API key.
 *
 * Every failure mode — unknown code, expired, already consumed, wrong
 * device_id, wrong PKCE verifier — returns the identical 400 body.
 * Distinguishing them would tell a guessing attacker which half of a
 * code+verifier pair they had right; same rationale as
 * AuthenticateWebOrMcpRequest's single 401 for every bad-token shape.
 */
class DevicePairingExchangeController extends Controller
{
    private const string INVALID_MESSAGE = 'Invalid or expired pairing code.';

    public function exchange(DevicePairingExchangeRequest $request): JsonResponse
    {
        /** @var array{code: string, code_verifier: string, device_id: string} $validated */
        $validated = $request->validated();
        $codeHash = User::hashMcpToken($validated['code']);

        $issued = DB::transaction(function () use ($validated, $codeHash): ?array {
            // Locked so a second, concurrent redemption of the same code
            // blocks on this row, sees consumed_at already set, and loses.
            $pairingCode = PhrDevicePairingCode::query()
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if ($pairingCode === null || ! $pairingCode->isRedeemable()) {
                return null;
            }

            if ($pairingCode->device_id !== $validated['device_id']) {
                return null;
            }

            $expectedChallenge = rtrim(strtr(
                base64_encode(hash('sha256', $validated['code_verifier'], true)),
                '+/',
                '-_'
            ), '=');

            if (! hash_equals($pairingCode->code_challenge, $expectedChallenge)) {
                return null;
            }

            $pairingCode->forceFill(['consumed_at' => now()])->save();

            $user = $pairingCode->user;

            // A code approved moments before the account was disabled must
            // not mint a key. The middleware would reject the key at use time
            // anyway (canLogin() is checked there too), but there is no
            // reason to create the row at all.
            if ($user === null || ! $user->canLogin()) {
                return null;
            }

            $issuedKey = PhrDeviceKey::issueFor($user, $pairingCode->device_id, $pairingCode->name);

            return [
                'plaintext' => $issuedKey['plaintext'],
                'expires_at' => $issuedKey['key']->expires_at,
                'device_name' => $pairingCode->name,
            ];
        });

        if ($issued === null) {
            return response()->json(['message' => self::INVALID_MESSAGE], 400);
        }

        return response()->json([
            'token' => $issued['plaintext'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'device_name' => $issued['device_name'],
        ]);
    }
}
