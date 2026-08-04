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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'converge' => [
        'enabled' => env('CONVERGE_ENABLED', false),
        'payment_sync_enabled' => env('CONVERGE_PAYMENT_SYNC_ENABLED', false),
        'payment_sync_interval_minutes' => env('CONVERGE_PAYMENT_SYNC_INTERVAL_MINUTES', 15),
        'payment_sync_lookback_days' => env('CONVERGE_PAYMENT_SYNC_LOOKBACK_DAYS', 30),
        'payment_sync_batch_size' => env('CONVERGE_PAYMENT_SYNC_BATCH_SIZE', 50),
        'http_timeout_seconds' => env('CONVERGE_HTTP_TIMEOUT_SECONDS', 90),
        'mode' => env('CONVERGE_MODE', 'sandbox'),
        'sandbox_base_url' => env('CONVERGE_SANDBOX_BASE_URL', 'https://api.demo.convergepay.com'),
        'production_base_url' => env('CONVERGE_PRODUCTION_BASE_URL', 'https://api.convergepay.com'),
        'sandbox_hpp_base_url' => env('CONVERGE_SANDBOX_HPP_BASE_URL', env('CONVERGE_SANDBOX_BASE_URL', 'https://api.demo.convergepay.com')),
        'production_hpp_base_url' => env('CONVERGE_PRODUCTION_HPP_BASE_URL', env('CONVERGE_PRODUCTION_BASE_URL', 'https://api.convergepay.com')),
        'merchant_id' => env('CONVERGE_MERCHANT_ID'),
        'user_id' => env('CONVERGE_USER_ID'),
        'pin' => env('CONVERGE_PIN'),
        'webhook_secret' => env('CONVERGE_WEBHOOK_SECRET'),
        'return_url' => env('CONVERGE_RETURN_URL'),
    ],

    'payment_simulation' => [
        'enabled' => env('PAYMENT_SIMULATION_ENABLED', false),
        'key' => env('PAYMENT_SIMULATION_KEY'),
    ],

    'zoom' => [
        'enabled' => env('ZOOM_MEETINGS_ENABLED', false),
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'oauth_base_url' => env('ZOOM_OAUTH_BASE_URL', 'https://zoom.us'),
        'base_url' => env('ZOOM_BASE_URL', 'https://api.zoom.us/v2'),
        'join_base_url' => env('ZOOM_JOIN_BASE_URL', 'https://zoom.us'),
    ],

    'outlook' => [
        'enabled' => env('OUTLOOK_SYNC_ENABLED', false),
        'tenant_id' => env('OUTLOOK_TENANT_ID'),
        'client_id' => env('OUTLOOK_CLIENT_ID'),
        'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
        'login_base_url' => env('OUTLOOK_LOGIN_BASE_URL', 'https://login.microsoftonline.com'),
        'socal_user_id' => env('OUTLOOK_SOCAL_USER_ID'),
        'socal_calendar_id' => env('OUTLOOK_SOCAL_CALENDAR_ID'),
        'legal_user_id' => env('OUTLOOK_LEGAL_USER_ID'),
        'legal_calendar_id' => env('OUTLOOK_LEGAL_CALENDAR_ID'),
        'base_url' => env('OUTLOOK_BASE_URL', 'https://graph.microsoft.com/v1.0'),
    ],

];
