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

    'identity' => [
        'url' => env('IDENTITY_API_URL', 'http://127.0.0.1:8000'),
        'client_secret' => env('IDENTITY_SSO_CLIENT_SECRET'),
    ],

    'github' => [
        'token' => env('GITHUB_TOKEN', env('GITHUB_PAT')),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'repo_watch_refresh_hours' => (int) env('GITHUB_REPO_WATCH_REFRESH_HOURS', 6),
        'repo_watch_scan_interval_hours' => (int) env('GITHUB_REPO_WATCH_SCAN_INTERVAL_HOURS', 6),
        'repo_watch_scan_batch_size' => (int) env('GITHUB_REPO_WATCH_SCAN_BATCH_SIZE', 5),
        'repo_watch_scan_delay_ms' => (int) env('GITHUB_REPO_WATCH_SCAN_DELAY_MS', 750),
        'repo_watch_rate_limit_floor' => (int) env('GITHUB_REPO_WATCH_RATE_LIMIT_FLOOR', 50),
        'repo_watch_scan_retry_seconds' => (int) env('GITHUB_REPO_WATCH_SCAN_RETRY_SECONDS', 300),
        'repo_watch_scanning_stale_minutes' => (int) env('GITHUB_REPO_WATCH_SCANNING_STALE_MINUTES', 20),
    ],

];
