<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Read-only access to one seller's Square account (#438). svc never calls
    // a Square write endpoint: the client allow-lists its reads. With no token,
    // or no bound workspace, the feature is off everywhere and every Square
    // operation is withheld. The token stays here: it is never stored in the
    // database, logged, returned, or placed in an exception.
    'square' => [
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        // production | sandbox; anything else leaves the feature off.
        'environment' => env('SQUARE_ENVIRONMENT', 'production'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        // Pinned Square API version, sent as Square-Version on every call.
        'version' => env('SQUARE_VERSION', '2026-09-16'),
        // Public id of the one workspace this account belongs to.
        'workspace' => env('SQUARE_WORKSPACE'),
        'timeout_seconds' => (float) env('SQUARE_TIMEOUT_SECONDS', 10),
        'connect_timeout_seconds' => (float) env('SQUARE_CONNECT_TIMEOUT_SECONDS', 5),
        // Total attempts for a read Square answers with 429 or 5xx, or that
        // cannot connect; the delay doubles from the base between attempts.
        'max_attempts' => (int) env('SQUARE_MAX_ATTEMPTS', 3),
        'retry_base_milliseconds' => (int) env('SQUARE_RETRY_BASE_MILLISECONDS', 250),
        // svc's own budget of pass-through reads per workspace per minute.
        'rate_limit_per_minute' => (int) env('SQUARE_RATE_LIMIT_PER_MINUTE', 60),
    ],

    'brevo' => [
        'dsn' => env('MAILER_DSN'),
        // Brevo signs nothing, so the delivery-status webhook is guarded by a
        // shared secret the caller has to present. Unset means the endpoint
        // refuses every request; see BrevoWebhookController for why that is the
        // right direction to fail in.
        'webhook_token' => env('BREVO_WEBHOOK_TOKEN'),
        'webhook_max_payload_bytes' => 1_048_576,
        'webhook_max_events' => 500,
        'webhook_rate_limit_per_minute' => 600,
    ],

];
