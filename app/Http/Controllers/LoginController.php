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

        // Master password support on localhost
        if ($this->isLocalhost() && $request->password === '1234567890') {
            $user = User::where('email', $email)->first();
            if ($user && $user->canLogin()) {
                Auth::login($user, $remember);
                $request->session()->regenerate();
                $this->auditLoginSucceeded($request, $user, 'password');

                return redirect()->intended('/');
            }
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

    /**
     * Development-only login that allows blank password.
     * Only works on localhost.
     */
    public function devLogin(Request $request)
    {
        // Only allow on localhost
        if (! $this->isLocalhost()) {
            abort(403, 'Dev login is only available on localhost');
        }

        $request->validate([
            'email' => 'required|email',
        ]);

        $email = $request->input('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->auditLoginFailed($request, null, $email, 'User not found', 'dev');

            return back()->withErrors(['email' => 'User not found']);
        }

        // Check if user has valid role to login
        if (! $user->canLogin()) {
            $this->auditLoginFailed($request, $user, $email, 'Account disabled', 'dev');

            return back()->withErrors(['email' => 'Your account is disabled. Please contact an administrator.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Update last login date
        $user->update(['last_login_date' => now()]);
        $this->auditLoginSucceeded($request, $user, 'dev');

        return redirect()->intended('/');
    }

    /**
     * Development-only login by user ID.
     * Only works on localhost.
     */
    public function devLoginById(Request $request): RedirectResponse
    {
        if (! $this->isLocalhost()) {
            abort(403, 'Dev login is only available on localhost');
        }

        $request->validate([
            'user_id' => 'required|integer',
        ]);

        $user = User::find($request->input('user_id'));

        if (! $user) {
            return back()->withErrors(['email' => 'User not found']);
        }

        if (! $user->canLogin()) {
            return back()->withErrors(['email' => 'Your account is disabled. Please contact an administrator.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->update(['last_login_date' => now()]);
        $this->auditLoginSucceeded($request, $user, 'dev');

        return redirect()->intended('/');
    }

    /**
     * Check if the request is coming from localhost.
     */
    private function isLocalhost(): bool
    {
        $appUrl = config('app.url', '');
        $appEnv = config('app.env', 'production');

        // Allow if APP_ENV is local
        if ($appEnv === 'local') {
            return true;
        }

        // Allow if APP_URL contains localhost
        if (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            return true;
        }

        return false;
    }
}
