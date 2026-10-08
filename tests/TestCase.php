<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing') ||
            ! (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:' ||
                getenv('CI_MYSQL_DISPOSABLE') === '1' && getenv('CI') === 'true' &&
                config('database.default') === 'mysql' && config('database.connections.mysql.host') === '127.0.0.1' &&
                config('database.connections.mysql.database') === 'jarvis_ci')) {
            throw new \RuntimeException('Tests require an explicitly isolated database.');
        }
        Http::preventStrayRequests();

        putenv('TASKFLOW_DAILY_USER_ID=');
        $_ENV['TASKFLOW_DAILY_USER_ID'] = '';
        $_SERVER['TASKFLOW_DAILY_USER_ID'] = '';

        config(['services.slack.allowed_user_id' => null]);
    }
}
