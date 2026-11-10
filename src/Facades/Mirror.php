<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Refatbd\LaravelChatGptMirror\Services\TokenPoolManager pool()
 * @method static \Refatbd\LaravelChatGptMirror\Services\ChatGptClient client()
 * @method static \Refatbd\LaravelChatGptMirror\Services\TokenRefresher refresher()
 * @method static string chat(array $messages, string $model = 'gpt-4o', bool $stream = false)
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
