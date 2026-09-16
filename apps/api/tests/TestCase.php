<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('SANDBOX_DRIVER=local');
        putenv('BUILD_DRIVER=stub');
        putenv('QUEUE_CONNECTION=sync');
        putenv('ARTIFACT_STORE=local');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_ENV['SANDBOX_DRIVER'] = 'local';
        $_ENV['BUILD_DRIVER'] = 'stub';
        $_ENV['QUEUE_CONNECTION'] = 'sync';
        $_ENV['ARTIFACT_STORE'] = 'local';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';
        $_SERVER['SANDBOX_DRIVER'] = 'local';
        $_SERVER['BUILD_DRIVER'] = 'stub';
        $_SERVER['QUEUE_CONNECTION'] = 'sync';
        $_SERVER['ARTIFACT_STORE'] = 'local';

        if (! defined('STUDIO_RUNNING_TESTS')) {
            define('STUDIO_RUNNING_TESTS', true);
        }

        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }
}
