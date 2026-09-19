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

    'uber_eats' => [
        'api_url' => env('UBER_EATS_API_URL', 'https://test-api.uber.com'),
        'token_url' => env('UBER_EATS_TOKEN_URL', 'https://sandbox-login.uber.com/oauth/v2/token'),
        'client_id' => env('UBER_EATS_CLIENT_ID'),
        'client_secret' => env('UBER_EATS_CLIENT_SECRET'),
        'webhook_secret' => env('UBER_EATS_WEBHOOK_SECRET'),
        'redirect_uri' => env('UBER_EATS_REDIRECT_URI'),
        'sandbox' => env('UBER_EATS_SANDBOX', true),
    ],

    'deliveroo' => [
        'api_url' => env('DELIVEROO_API_URL', 'https://api.developers.deliveroo.com'),
        'auth_url' => env('DELIVEROO_AUTH_URL', 'https://auth.developers.deliveroo.com'),
        'client_id' => env('DELIVEROO_CLIENT_ID'),
        'client_secret' => env('DELIVEROO_CLIENT_SECRET'),
        'webhook_secret' => env('DELIVEROO_WEBHOOK_SECRET'),
    ],

];
