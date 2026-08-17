<?php

return [
    'mutation_digest_key' => env('AGENT_API_MUTATION_DIGEST_KEY'),
    'audit_retention_days' => (int) env('AGENT_API_AUDIT_RETENTION_DAYS', 365),
    'authentication_attempts_per_minute' => (int) env('AGENT_API_AUTH_ATTEMPTS_PER_MINUTE', 300),
    'token_exchange_attempts_per_minute' => (int) env('AGENT_API_TOKEN_EXCHANGE_ATTEMPTS_PER_MINUTE', 60),
    'authorization_attempts_per_minute' => (int) env('AGENT_API_AUTHORIZATION_ATTEMPTS_PER_MINUTE', 30),
    'client_registrations_per_hour' => (int) env('AGENT_API_CLIENT_REGISTRATIONS_PER_HOUR', 10),
    'mcp_max_body_bytes' => (int) env('AGENT_API_MCP_MAX_BODY_BYTES', 262_144),
    'mcp_session_ttl_seconds' => (int) env('AGENT_API_MCP_SESSION_TTL_SECONDS', 1800),
    'mcp_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('AGENT_API_MCP_ALLOWED_ORIGINS', '')),
    ))),
];
