<?php

return [
    'audit_retention_days' => (int) env('AGENT_API_AUDIT_RETENTION_DAYS', 365),
    'authentication_attempts_per_minute' => (int) env('AGENT_API_AUTH_ATTEMPTS_PER_MINUTE', 300),
    'token_exchange_attempts_per_minute' => (int) env('AGENT_API_TOKEN_EXCHANGE_ATTEMPTS_PER_MINUTE', 60),
    'authorization_attempts_per_minute' => (int) env('AGENT_API_AUTHORIZATION_ATTEMPTS_PER_MINUTE', 30),
];
