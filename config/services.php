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
        'bot_token' => env('SLACK_BOT_TOKEN'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'default_channel' => env('SLACK_DEFAULT_CHANNEL'),
        'miriam_channel_id' => env('SLACK_MIRIAM_CHANNEL_ID'),
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
        'allowed_user_id' => env('SLACK_ALLOWED_USER_ID'),
        // Read through config so behaviour is identical with and without
        // `php artisan config:cache`; env() at runtime returns null once the
        // config is cached, which silently changed who captures were filed to.
        'daily_user_id' => env('TASKFLOW_DAILY_USER_ID'),
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model_default' => env('OPENAI_MODEL_DEFAULT', 'gpt-4o-mini'),
        'model_planner' => env('OPENAI_MODEL_PLANNER', 'gpt-5.4-mini'),
    ],

    'miriam_ai' => [
        'enabled' => env('MIRIAM_AI_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('MIRIAM_AI_MODEL', 'gpt-5.4-mini'),
        'reasoning_effort' => env('MIRIAM_AI_REASONING_EFFORT', 'low'),
    ],

    'miriam_capture' => [
        'pending_confirmation_minutes' => env('MIRIAM_CAPTURE_PENDING_CONFIRMATION_MINUTES', 720),
        'second_poke_minutes' => env('MIRIAM_REMINDER_SECOND_POKE_MINUTES', 30),
        'final_poke_minutes' => env('MIRIAM_REMINDER_FINAL_POKE_MINUTES', 120),
        'max_pokes' => env('MIRIAM_REMINDER_MAX_POKES', 3),
    ],

    'google_calendar' => [
        'enabled' => env('GOOGLE_CALENDAR_ENABLED', filled(env('GOOGLE_CLIENT_ID')) && filled(env('GOOGLE_CLIENT_SECRET'))),
        'client_id' => env('GOOGLE_CLIENT_ID', env('GOOGLE_CALENDAR_CLIENT_ID')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', env('GOOGLE_CALENDAR_CLIENT_SECRET')),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', env('GOOGLE_CALENDAR_REDIRECT_URI', 'https://friday.paulstechnologies.com/google/calendar/callback')),
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events',
        ],
    ],

    'ai_assistant' => [
        'enabled' => env('AI_ASSISTANT_ENABLED', false),
        'provider' => env('AI_PROVIDER', 'mock'),
        'model' => env('AI_MODEL'),
        'api_key' => env('AI_API_KEY'),
        'local_endpoint' => env('AI_LOCAL_ENDPOINT'),
    ],

];
