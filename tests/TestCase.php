<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Refatbd\LaravelChatGptMirror\MirrorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MirrorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:yk26u08k1fTz8P5b4/oN+Y7qFw4R8e9k1fTz8P5b4/o=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('mirror.api_key', 'test-mirror-key-12345');
        $app['config']->set('mirror.pool.strategy', 'least_busy');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
