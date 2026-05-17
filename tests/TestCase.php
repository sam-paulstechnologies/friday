<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('TASKFLOW_DAILY_USER_ID=');
        $_ENV['TASKFLOW_DAILY_USER_ID'] = '';
        $_SERVER['TASKFLOW_DAILY_USER_ID'] = '';

        config(['services.slack.allowed_user_id' => null]);
    }
}
