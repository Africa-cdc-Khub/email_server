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

    /*
    | Simple word+number image CAPTCHA for admin send-mail + provider test email.
    | No third-party / Google dependency. Disable with CAPTCHA_ENABLED=false.
    */
    'captcha' => [
        'enabled' => filter_var(env('CAPTCHA_ENABLED', true), FILTER_VALIDATE_BOOL),
        'length' => (int) env('CAPTCHA_LENGTH', 5),
        'ttl' => (int) env('CAPTCHA_TTL', 600),
    ],

    /*
    | Email attachments accepted by integration + admin send APIs (base64 JSON).
    */
    'mail_attachments' => [
        'max_count' => (int) env('MAIL_ATTACHMENT_MAX_COUNT', 10),
        'max_bytes_per_file' => (int) env('MAIL_ATTACHMENT_MAX_BYTES', 5 * 1024 * 1024),
        'max_bytes_total' => (int) env('MAIL_ATTACHMENT_MAX_TOTAL_BYTES', 15 * 1024 * 1024),
    ],

];
