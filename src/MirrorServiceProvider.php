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
use Refatbd\LaravelChatGptMirror\Services\AssetTicketService;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\ConversationService;
use Refatbd\LaravelChatGptMirror\Services\Convert\ConversionFacade;
use Refatbd\LaravelChatGptMirror\Services\Convert\Gif89aCodec;
use Refatbd\LaravelChatGptMirror\Services\Convert\GptGifCodec;
use Refatbd\LaravelChatGptMirror\Services\Convert\GptGifV4Codec;
use Refatbd\LaravelChatGptMirror\Services\Convert\LoafCodec;
use Refatbd\LaravelChatGptMirror\Services\Convert\PngSpeakCodec;
use Refatbd\LaravelChatGptMirror\Services\FailureTracker;
use Refatbd\LaravelChatGptMirror\Services\ProofOfWorkSolver;
use Refatbd\LaravelChatGptMirror\Services\RichOutputService;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;
use Refatbd\LaravelChatGptMirror\Services\ToolBridgeService;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;

class MirrorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mirror.php', 'mirror');

        $this->app->singleton(FailureTracker::class, fn () => new FailureTracker());
        $this->app->singleton(SecurityService::class, fn () => new SecurityService());
        $this->app->singleton(ProofOfWorkSolver::class, fn () => new ProofOfWorkSolver());
        $this->app->singleton(UrlRewriter::class, fn () => new UrlRewriter());
        $this->app->singleton(ToolBridgeService::class, fn () => new ToolBridgeService());

        $this->app->singleton(AssetTicketService::class, fn ($app) => new AssetTicketService(
            $app->make(SecurityService::class)
        ));

        $this->app->singleton(TokenRefresher::class, fn () => new TokenRefresher());
        $this->app->singleton(ChatGptClient::class, fn ($app) => new ChatGptClient(
            $app->make(ProofOfWorkSolver::class)
        ));
        $this->app->singleton(BrowserPatchManager::class, fn ($app) => new BrowserPatchManager(
            $app->make(UrlRewriter::class)
        ));
        $this->app->singleton(StreamTransformerInterface::class, fn () => new StreamTransformer());
        $this->app->singleton(StreamTransformer::class, fn ($app) => $app->make(StreamTransformerInterface::class));

        $this->app->singleton(TokenPoolInterface::class, fn ($app) => new TokenPoolManager(
            $app->make(TokenRefresher::class)
        ));
        $this->app->singleton(TokenPoolManager::class, fn ($app) => $app->make(TokenPoolInterface::class));

        $this->app->singleton(RichOutputService::class, fn ($app) => new RichOutputService(
            $app->make(ChatGptClient::class),
            $app->make(AssetTicketService::class)
        ));

        $this->app->singleton(ConversationService::class, fn ($app) => new ConversationService(
            $app->make(ChatGptClient::class),
            $app->make(TokenPoolManager::class)
        ));

        $this->app->singleton(LoafCodec::class, fn () => new LoafCodec());
        $this->app->singleton(PngSpeakCodec::class, fn () => new PngSpeakCodec());
        $this->app->singleton(Gif89aCodec::class, fn () => new Gif89aCodec());
        $this->app->singleton(GptGifCodec::class, fn ($app) => new GptGifCodec($app->make(Gif89aCodec::class)));
        $this->app->singleton(GptGifV4Codec::class, fn ($app) => new GptGifV4Codec($app->make(Gif89aCodec::class)));
        $this->app->singleton(ConversionFacade::class, fn ($app) => new ConversionFacade(
            $app->make(LoafCodec::class),
            $app->make(PngSpeakCodec::class),
            $app->make(Gif89aCodec::class),
            $app->make(GptGifCodec::class),
            $app->make(GptGifV4Codec::class)
        ));

        $this->app->singleton(Mirror::class, fn ($app) => new Mirror(
            $app->make(TokenPoolInterface::class),
            $app->make(ChatGptClient::class),
            $app->make(TokenRefresher::class)
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mirror.php' => config_path('mirror.php'),
            ], 'mirror-config');

            $this->commands([
                InstallCommand::class,
                AddTokenCommand::class,
                ImportTokenCommand::class,
                ListTokensCommand::class,
                RefreshTokensCommand::class,
                HealthCheckCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }
}
