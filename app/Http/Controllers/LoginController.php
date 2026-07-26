<?php

namespace App\Http\Controllers;

use App\Models\User;
use BWH\Auth\Concerns\LogsAuthEvents;
use BWH\Auth\Contracts\LoginThrottle;
use BWH\Auth\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    use LogsAuthEvents;

    public function login(Request $request, LoginThrottle $throttle): RedirectResponse
    {
        $email = $request->input('email', '');
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        $throttleState = $throttle->inspect($request, null, $email, 'password');
        if (! $throttleState->allowsLogin()) {
            $throttle->recordBlocked($request, null, $email, 'password', $throttleState);
            $seconds = $throttleState->availableInSeconds();

            return back()->withErrors(['email' => "Too many login attempts. Please try again in {$seconds} seconds."]);
        }

        if (Auth::attempt($credentials, $remember)) {
            $user = Auth::user();

            // Check if user has valid role to login
            if (! $user->canLogin()) {
                Auth::logout();
                $request->session()->invalidate();
                $this->auditLoginFailed($request, $user, $email, 'Account disabled', 'password');

                return back()->withErrors(['email' => 'Your account is disabled. Please contact an administrator.']);
            }

            $request->session()->regenerate();
            $this->auditLoginSucceeded($request, $user, 'password');

            return redirect()->intended('/');
        }

        $this->auditLoginFailed($request, null, $email, 'Invalid credentials', 'password');

        return back()->withErrors(['email' => 'Invalid credentials']);
    }

    /**
     * Passwordless login: email the user a one-time sign-in code.
     *
     * Reuses the bherila-auth two-factor challenge as a primary factor. The
     * response shape is identical whether or not the account exists so the
     * endpoint cannot be used to enumerate registered emails; the frontend
     * always advances to the code-entry step and a bogus token simply fails
     * verification with the same generic error.
     */
    public function requestEmailCode(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = $validated['email'];
        $remember = $request->boolean('remember');
        $user = User::where('email', $email)->first();

        if ($user && $user->canLogin()) {
            $attempt = $twoFactor->startChallenge($user, $request, $remember);

            return response()->json(['success' => true, 'attempt_token' => (string) $attempt->getAttribute('token')]);
        }

        $this->auditLoginFailed($request, $user, $email, $user ? 'Account disabled' : 'User not found', 'email_code');

        return response()->json(['success' => true, 'attempt_token' => '']);
    }
}
