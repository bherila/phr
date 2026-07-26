<?php

use App\Http\Middleware\ThrottleTwoFactorVerify;
use App\Models\User;

return [
    'routes' => [
        'enabled' => true,
        'prefix' => 'api',
        'middleware' => ['web', ThrottleTwoFactorVerify::class],
        'passkeys' => true,
        'password_resets' => true,
        'change_password' => true,
        'two_factor' => true,
    ],

    'migrations' => [
        'drop_tables_on_rollback' => false,
    ],

    'audit' => [
        // 'null' discards events (default); 'database' persists them to the audit table.
        'driver' => env('BHERILA_AUTH_AUDIT_DRIVER', 'database'),
        'table' => 'auth_audit_log',
        // Expose the package's read endpoints (own login history + admin list). Off by default.
        'routes_enabled' => env('BHERILA_AUTH_AUDIT_ROUTES', false),
        // null = retain forever (no pruning). Set a positive integer to enable `model:prune`.
        'retention_days' => env('BHERILA_AUTH_AUDIT_RETENTION_DAYS'),
        // Gate ability required for the cross-user admin endpoint; null disables that route.
        // IMPORTANT: the ability must verify that the user is active/approved AND is an admin.
        // The package enforces its own RequireActiveUser check on top of this gate, but the
        // gate should still verify account state independently so your Gate definition is
        // correct even when called from other locations. Example: check both ->is_admin and
        // ->approved_at, not just the role.
        'admin_ability' => env('BHERILA_AUTH_AUDIT_ADMIN_ABILITY'),
    ],

    'throttle' => [
        // Opt-in brute-force lockout backed by auth_audit_log rows. Disabled by default.
        'enabled' => env('BHERILA_AUTH_THROTTLE_ENABLED', true),
        'max_attempts' => env('BHERILA_AUTH_THROTTLE_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('BHERILA_AUTH_THROTTLE_DECAY_MINUTES', 15),
        // How failed attempts are grouped into a lockout key:
        //   'email'    — per account: count an email's failures across all source IPs
        //   'ip'       — per source: count an IP's failures across all emails
        //   'email_ip' — per account+source pair (most conservative; default)
        // Any other value falls back to 'email_ip'.
        'key' => env('BHERILA_AUTH_THROTTLE_KEY', 'email_ip'),
        'record_blocked' => env('BHERILA_AUTH_THROTTLE_RECORD_BLOCKED', true),
    ],

    'password_resets' => [
        'reset_url' => env('BHERILA_AUTH_PASSWORD_RESET_URL', env('APP_URL', '').'/reset-password/{token}?email={email}'),
        'request_url' => env('BHERILA_AUTH_PASSWORD_REQUEST_URL', '/forgot-password'),
        'redirect_after_reset' => env('BHERILA_AUTH_PASSWORD_RESET_REDIRECT', '/'),
        'mail_subject' => env('BHERILA_AUTH_PASSWORD_RESET_MAIL_SUBJECT', 'Reset your :app password'),
        'notice_subject' => env('BHERILA_AUTH_PASSWORD_NOTICE_MAIL_SUBJECT', 'Your :app password was changed'),
        'verify_email_on_reset' => false,
    ],

    'two_factor' => [
        'table' => 'auth_two_factor_attempts',
        'expires_minutes' => 15,
        'allow_test_code' => env('BHERILA_AUTH_ALLOW_TEST_2FA_CODE', env('APP_ENV') !== 'production'),
        'test_code' => '999999',
        'mail_subject' => env('BHERILA_AUTH_TWO_FACTOR_MAIL_SUBJECT', 'Verify your login - :app'),
        'login_url' => env('BHERILA_AUTH_LOGIN_URL', '/login'),
        'session_user_key' => 'bherila_auth_2fa_user_id',
        'session_remember_key' => 'bherila_auth_2fa_remember',
    ],

    'passkeys' => [
        'table' => 'auth_passkeys',
        'rp_name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'App')),
        'allowed_origins' => array_filter(array_map('trim', explode(',', env('WEBAUTHN_ALLOWED_ORIGINS', '')))),
        'timeout' => 60000,
        'resident_key' => env('WEBAUTHN_RESIDENT_KEY', 'preferred'),
        'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'preferred'),
    ],

    'users' => [
        'model' => config('auth.providers.users.model', User::class),
        'name_attribute' => 'name',
        'email_attribute' => 'email',
        'force_change_password_attribute' => null,
    ],
];
