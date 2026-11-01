<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // openFDA drug NDC directory. The key is optional: without one openFDA
    // allows 1,000 requests a day per IP. Only drug descriptors are ever sent.
    'openfda' => [
        'key' => env('OPENFDA_API_KEY'),
        'base_url' => env('OPENFDA_BASE_URL', 'https://api.fda.gov'),
        'timeout' => (int) env('OPENFDA_TIMEOUT_SECONDS', 5),
        'cache_ttl_seconds' => (int) env('OPENFDA_CACHE_TTL_SECONDS', 86400),
        'search_cache_ttl_seconds' => (int) env('OPENFDA_SEARCH_CACHE_TTL_SECONDS', 600),
        'web_requests_per_minute' => (int) env('OPENFDA_WEB_REQUESTS_PER_MINUTE', 30),
        'negative_cache_ttl_seconds' => (int) env('OPENFDA_NEGATIVE_CACHE_TTL_SECONDS', 21600),
    ],

    // NLM RxNav (RxNorm) NDC history, the fallback for NDCs openFDA no longer lists (discontinued
    // products). No key is needed; NLM allows 20 requests a second per IP. Only an NDC or an
    // RxCUI is ever sent.
    'rxnav' => [
        'base_url' => env('RXNAV_BASE_URL', 'https://rxnav.nlm.nih.gov/REST'),
        'timeout' => (int) env('RXNAV_TIMEOUT_SECONDS', 5),
        'cache_ttl_seconds' => (int) env('RXNAV_CACHE_TTL_SECONDS', 86400),
        'negative_cache_ttl_seconds' => (int) env('RXNAV_NEGATIVE_CACHE_TTL_SECONDS', 21600),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
