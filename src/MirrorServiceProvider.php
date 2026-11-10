<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror;

use Illuminate\Support\ServiceProvider;
use Refatbd\LaravelChatGptMirror\Commands\AddTokenCommand;
use Refatbd\LaravelChatGptMirror\Commands\HealthCheckCommand;
use Refatbd\LaravelChatGptMirror\Commands\ImportTokenCommand;
use Refatbd\LaravelChatGptMirror\Commands\InstallCommand;
use Refatbd\LaravelChatGptMirror\Commands\ListTokensCommand;
use Refatbd\LaravelChatGptMirror\Commands\RefreshTokensCommand;
use Refatbd\LaravelChatGptMirror\Contracts\StreamTransformerInterface;
use Refatbd\LaravelChatGptMirror\Contracts\TokenPoolInterface;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;

class MirrorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mirror.php', 'mirror');

        $this->app->singleton(TokenRefresher::class, function () {
            return new TokenRefresher();
        });

        $this->app->singleton(ChatGptClient::class, function () {
            return new ChatGptClient();
        });

        $this->app->singleton(BrowserPatchManager::class, function () {
            return new BrowserPatchManager();
        });

        $this->app->bind(StreamTransformerInterface::class, StreamTransformer::class);

        $this->app->singleton(TokenPoolInterface::class, TokenPoolManager::class);
        $this->app->singleton(TokenPoolManager::class, function ($app) {
            return new TokenPoolManager($app->make(TokenRefresher::class));
        });

        $this->app->singleton(Mirror::class, function ($app) {
            return new Mirror(
                $app->make(TokenPoolManager::class),
                $app->make(ChatGptClient::class),
                $app->make(TokenRefresher::class)
            );
        });

        $this->app->alias(Mirror::class, 'laravel-chatgpt-mirror');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mirror.php' => config_path('mirror.php'),
            ], 'mirror-config');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

            $this->commands([
                InstallCommand::class,
                AddTokenCommand::class,
                ImportTokenCommand::class,
                ListTokensCommand::class,
                RefreshTokensCommand::class,
                HealthCheckCommand::class,
            ]);
        }

        if (config('mirror.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }
    }
}
