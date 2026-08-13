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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    // See epayment-integration.md §1.2/§2. One shared toggle for every
    // vendor gateway — not tied to APP_ENV, since a production app instance
    // may still need to run sandbox-only smoke tests. Every actual vendor
    // credential (access token/secret, webhook signing key, account id)
    // lives in event_epayment_configs, one row per (Event, environment) —
    // see EventEpaymentConfig's own docblock. This is deliberately the only
    // payments config value left here; SQUARE_ACCESS_TOKEN/
    // SQUARE_SANDBOX_ACCESS_TOKEN/SQUARE_SANDBOX_APPLICATION_ID/
    // SQUARE_SANDBOX_LOCATION were an earlier, incorrect app-wide-credential
    // design (a real bug, caught 2026-08-13 — see §1.2) and are gone, not
    // just unused.
    'payments' => [
        'environment' => env('PAYMENTS_ENVIRONMENT', 'sandbox'),
    ],

];
