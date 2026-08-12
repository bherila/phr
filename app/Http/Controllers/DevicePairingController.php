<?php

namespace App\Http\Controllers;

use App\Http\Requests\DevicePairingApproveRequest;
use App\Http\Requests\DevicePairingDenyRequest;
use App\Http\Requests\DevicePairingShowRequest;
use App\Models\PhrDevicePairingCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Browser-approved device pairing for the Sinus Sentinel Mac app.
 *
 * Replaces `php artisan mcp:token:issue` plus a human pasting the key over
 * shell access: the app opens show()'s URL in the user's browser (an
 * already-authenticated session, or one that gets there transparently via the
 * OAuth login's redirect()->intended()), the user approves or denies here, and
 * the app exchanges the resulting one-time code for a per-device key via
 * DevicePairingExchangeController — never over a channel the human, or this
 * controller, ever sees the finished key on.
 */
class DevicePairingController extends Controller
{
    public function show(DevicePairingShowRequest $request): View
    {
        $validated = $request->validated();

        return view('device-pairing.approve', [
            'deviceId' => $validated['device_id'],
            'deviceName' => $validated['name'],
            'codeChallenge' => $validated['code_challenge'],
            'redirectUri' => $validated['redirect_uri'],
        ]);
    }

    public function approve(DevicePairingApproveRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $issued = PhrDevicePairingCode::issueFor(
            user: Auth::user(),
            deviceId: $validated['device_id'],
            name: $validated['name'],
            codeChallenge: $validated['code_challenge'],
        );

        return redirect()->away(
            $validated['redirect_uri'].'?'.http_build_query(['code' => $issued['plaintext']])
        );
    }

    public function deny(DevicePairingDenyRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        return redirect()->away(
            $validated['redirect_uri'].'?'.http_build_query(['error' => 'denied'])
        );
    }
}
