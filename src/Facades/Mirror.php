<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Refatbd\LaravelChatGptMirror\Services\TokenPoolManager pool()
 * @method static \Refatbd\LaravelChatGptMirror\Services\ChatGptClient client()
 * @method static \Refatbd\LaravelChatGptMirror\Services\TokenRefresher refresher()
 * @method static \Refatbd\LaravelChatGptMirror\Services\ConversationService conversations()
 * @method static \Refatbd\LaravelChatGptMirror\Services\MirrorCache cache()
 * @method static \Refatbd\LaravelChatGptMirror\Services\SecurityService security()
 * @method static \Refatbd\LaravelChatGptMirror\Services\DynamicConfigService config()
 * @method static string chat(array $messages, string $model = 'gpt-4o', bool $stream = false, ?callable $onDelta = null)
 * @method static void ignoreMigrations()
 * @method static void useMigrations()
 * @method static void ignoreRoutes()
 * @method static void useRoutes()
 * @method static void authenticateApiKeyUsing(?callable $callback)
 * @method static void authorizeAdminUsing(?callable $callback)
 * @method static void resetConfigurationHooks()
 *
 * @see \Refatbd\LaravelChatGptMirror\Mirror
 */
class Mirror extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-chatgpt-mirror';
    }
}
