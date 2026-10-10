<?php

use App\Http\Middleware\ThrottleTwoFactorVerify;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\AgentOAuthServer;

return [
    'routes' => [
        'enabled' => false,
        'prefix' => 'api',
        'middleware' => ['web', ThrottleTwoFactorVerify::class],
        'passkeys' => false,
        'password_resets' => false,
        'change_password' => false,
        'two_factor' => false,
    ],

    // Every per-client limit keys on Request::ip(). Behind Cloudflare that is the
    // edge's address unless the edge is trusted, so all of an edge's callers would
    // share one budget. Only Cloudflare's published ranges are trusted by default;
    // a direct connection's forwarded headers are ignored. A deployment behind
    // another proxy lists it in TRUSTED_PROXIES; one with no proxy sets it empty.
    'trusted_proxies' => [
        'apply' => (bool) env('BHERILA_AUTH_TRUSTED_PROXIES', true),
        'trusted' => env('TRUSTED_PROXIES', 'cloudflare'),
        'cloudflare' => null,
    ],

    'oauth_client' => [
        'provider' => env('OAUTH_PROVIDER', 'bherila'),
        'base_url' => env('OAUTH_PROVIDER_URL', 'https://id.bherila.net'),
        'client_id' => env('OAUTH_CLIENT_ID'),
        'client_secret' => env('OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('OAUTH_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/oauth/callback'),
        'scope' => env('OAUTH_SCOPE', 'identity:read'),
        'authorize_path' => '/oauth/authorize',
        'token_path' => '/oauth/token',
        'identity_path' => '/api/oauth/user',
        // Signing out locally leaves the provider still recognising the person, so the next
        // sign-in returns them without a prompt and the button reads as having done nothing.
        // Named here because this block is restated in full: `mergeConfigFrom` is a shallow
        // merge, so an omitted key is blank rather than inherited, and `endSessionUrl()`
        // aborts 503 on a blank one.
        'end_session_path' => '/oauth/end-session',
    ],

    // The agent-API authorization-server profile: every URL derives from APP_URL,
    // so a fork needs no host-specific settings. The preset binds credentials to
    // APP_URL/api/v1 (an omitted `resource` is taken as that one), requires S256
    // PKCE for every client, and allows public-only self-registration.
    // Provider identity enforcement: end browser sessions and OAuth credentials
    // whose identity was disabled, deleted, reset or ungranted at the identity
    // provider. Off until BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED=true; while
    // off, sign-in still records each session's baseline and credentials carry
    // it, so enabling enforcement later retires only what predates that.
    'provider_identity' => [
        'enabled' => (bool) env('BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED', false),
        // A store every web worker shares and that supports locks (database,
        // redis, file); null uses the default cache store.
        'cache_store' => env('BHERILA_AUTH_PROVIDER_IDENTITY_CACHE_STORE') ?: null,
        'binding' => [
            'provider_column' => 'oauth_provider',
            'subject_column' => 'oauth_subject',
        ],
        'expired_redirect_route' => 'login',
        'except_routes' => ['logout'],
        'bearer_guard' => 'api',
    ],

    'oauth_server' => AgentOAuthServer::config(AgentApiScopes::descriptions(), [
        // Two protected resources, each its own audience and RFC 9728 document
        // (/.well-known/oauth-protected-resource/api/v1 and .../api/v1/mcp): a
        // token issued for one is refused at the other. Clients that send no
        // `resource` get REST credentials.
        'resources' => [
            'rest' => ['path' => '/api/v1', 'scopes' => AgentApiScopes::restIds()],
            'mcp' => ['path' => '/api/v1/mcp', 'scopes' => AgentApiScopes::mcpIds()],
        ],
        'assume_omitted_resource' => 'rest',
        // Browser origins allowed to call discovery, registration and the token
        // endpoint directly (comma-separated; `*` for any). Empty, the default,
        // sends no CORS headers. The MCP endpoint's own origin policy is
        // AGENT_API_MCP_ALLOWED_ORIGINS, enforced by the application.
        'cors' => [
            'allowed_origins' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('OAUTH_SERVER_CORS_ALLOWED_ORIGINS', '')),
            ))),
        ],
        'resource_required_scope' => AgentApiScopes::MCP_USE,
        'resource_required_scopes' => [AgentApiScopes::MCP_USE],
        // A signed-in person's own API tokens and OAuth apps, for connectors that
        // ask for a key or run their own authorization-code flow.
        'credentials' => [
            'enabled' => true,
            'prefix' => 'account/api-credentials',
            'token_lifetimes' => ['PT4H', 'P30D', 'P90D', 'P365D'],
        ],
        'dynamic_clients' => [
            'required_columns' => ['dynamically_registered_at', 'scopes'],
            'registered_at_column' => 'dynamically_registered_at',
            'last_used_at_column' => null,
            'scopes_column' => 'scopes',
            'enforce_registered_scopes' => true,
        ],
        'authorization_state' => [
            'cache_prefix' => 'oauth-resource:',
            'ttl_seconds' => null,
        ],
        'consent' => [
            'app_name' => 'PHR',
            'heading' => 'Connect :client to :app?',
            'intro' => 'This application is requesting access to your personal health record.',
            'identity' => true,
            'trust_warning' => 'Only continue if you recognize and trust this application. You can disconnect it later.',
            'dynamic_client_warning' => 'This client registered automatically. After approval, your browser returns to:',
            'policy_notice' => 'Patient access and the granted clinical, document, and import permissions still apply to every request.',
            'approve_label' => 'Authorize',
            'deny_label' => 'Cancel',
        ],
    ]),

    // The identity provider's delegated access endpoint (POST /application-access, contract
    // version 3), answered by App\Services\Accounts\PhrApplicationAccessAdapter. Restated in full
    // because `mergeConfigFrom` is shallow. Off by default; writes are a separate switch, also off.
    // See docs/delegated-access.md.
    'delegated_access' => [
        'enabled' => env('PHR_DELEGATED_ACCESS_ENABLED', false),
        'writes_enabled' => env('PHR_DELEGATED_ACCESS_WRITES_ENABLED', false),
        // Must equal oauth_client.base_url: subjects are resolved in the sign-in provider's namespace.
        'issuer' => env('PHR_DELEGATED_ACCESS_ISSUER', ''),
        // This endpoint's exact HTTPS URL, as the provider calls it.
        'endpoint' => env('PHR_DELEGATED_ACCESS_ENDPOINT', ''),
        // This application's key in the provider's application registry.
        'application' => env('PHR_DELEGATED_ACCESS_APPLICATION', ''),
        // `key-id|/path/to/public.pem`, comma-separated; list a second key only while rotating.
        'public_keys' => env('PHR_DELEGATED_ACCESS_PUBLIC_KEYS', ''),
        // Must be set and equal oauth_client.provider: account bindings are stored under that name.
        'oauth_provider' => env('OAUTH_PROVIDER'),
        // The nonce table's connection; null for the default. Must be durable and shared by every worker.
        'nonce_connection' => env('PHR_DELEGATED_ACCESS_NONCE_CONNECTION'),
        // The operation receipts table's connection; the nonce connection unless set. Durable and
        // shared by every worker: a lost receipt lets a repeated write run again.
        'receipt_connection' => env('PHR_DELEGATED_ACCESS_RECEIPT_CONNECTION', env('PHR_DELEGATED_ACCESS_NONCE_CONNECTION')),
        'path' => '/application-access',
        'per_minute' => 120,
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
