<?php

function isolatedE2eEnvironment(): void
{
    $root = dirname(__DIR__, 2);
    $directory = $root.'/test-results';
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    foreach ([
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'APP_URL' => 'http://127.0.0.1:8765',
        'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php',
        'DB_URL' => '',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $directory.'/e2e.sqlite',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'file',
        'AI_ASSISTANT_ENABLED' => 'false',
        'MIRIAM_AI_ENABLED' => 'false',
        'GOOGLE_CALENDAR_ENABLED' => 'false',
        'OPENAI_API_KEY' => '',
        'SLACK_BOT_TOKEN' => '',
        'SLACK_WEBHOOK_URL' => '',
        'SLACK_BOT_USER_OAUTH_TOKEN' => '',
        'GOOGLE_CLIENT_ID' => '',
        'GOOGLE_CLIENT_SECRET' => '',
        'GOOGLE_CALENDAR_CLIENT_ID' => '',
        'GOOGLE_CALENDAR_CLIENT_SECRET' => '',
    ] as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    if (file_exists(getenv('APP_CONFIG_CACHE'))) {
        throw new RuntimeException('Unexpected test configuration cache.');
    }
}
