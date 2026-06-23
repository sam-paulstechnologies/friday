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
