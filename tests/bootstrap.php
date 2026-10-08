<?php

// Test configuration is intentionally independent of .env and the caller's DB/provider environment.
$mysql = getenv('CI_MYSQL_DISPOSABLE') === '1' && getenv('CI') === 'true';
foreach ([
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_CONFIG_CACHE' => __DIR__.'/../test-results/no-config-cache.php',
    'DB_URL' => '',
    'DB_SOCKET' => '',
    'DB_CONNECTION' => $mysql ? 'mysql' : 'sqlite',
    'DB_DATABASE' => $mysql ? 'jarvis_ci' : ':memory:',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_USERNAME' => 'jarvis_ci',
    'DB_PASSWORD' => 'synthetic-ci-password',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'OPENAI_API_KEY' => '',
    'SLACK_BOT_TOKEN' => '',
    'SLACK_WEBHOOK_URL' => '',
    'GOOGLE_CALENDAR_CLIENT_ID' => '',
    'GOOGLE_CALENDAR_CLIENT_SECRET' => '',
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
    'SLACK_BOT_USER_OAUTH_TOKEN' => '',
    'TELEGRAM_BOT_TOKEN' => '',
    'AI_ASSISTANT_ENABLED' => 'false',
    'MIRIAM_AI_ENABLED' => 'false',
    'GOOGLE_CALENDAR_ENABLED' => 'false',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
if (file_exists(getenv('APP_CONFIG_CACHE'))) {
    throw new RuntimeException('Unexpected test configuration cache.');
}
require __DIR__.'/../vendor/autoload.php';
