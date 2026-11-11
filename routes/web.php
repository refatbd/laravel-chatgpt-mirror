<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\AdminController;
use Refatbd\LaravelChatGptMirror\Controllers\BrowserProxyController;
use Refatbd\LaravelChatGptMirror\Controllers\HelpController;
use Refatbd\LaravelChatGptMirror\Controllers\PlaygroundController;
use Refatbd\LaravelChatGptMirror\Http\Middleware\AuthorizeAdmin;
use Refatbd\LaravelChatGptMirror\Http\Middleware\PrepareMirrorRuntime;
use Refatbd\LaravelChatGptMirror\Http\Middleware\ProtectControlEndpoints;

if (!config('mirror.enabled', true)) {
    return;
}

$browserPrefix = trim((string) config('mirror.routes.browser_prefix', ''), '/');
$adminPrefix = trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/');
$playgroundPrefix = trim((string) config('mirror.routes.playground_prefix', 'mirror/playground'), '/');
$helpPrefix = trim((string) config('mirror.routes.help_prefix', 'mirror/help'), '/');
$assetsPrefix = trim((string) config('mirror.routes.assets_prefix', 'mirror/assets'), '/');
$openaiPrefix = trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');
$backendPrefix = trim((string) config('mirror.routes.backend_prefix', 'backend-api'), '/');
$docsPrefix = trim((string) config('mirror.routes.docs_prefix', 'mirror'), '/');

$domain = trim((string) (config('mirror.routes.domain') ?? ''));
$webExtraMiddleware = array_values(array_filter((array) config('mirror.routes.web_middleware', [])));
$adminExtraMiddleware = array_values(array_filter((array) config('mirror.routes.admin_middleware', [])));

$features = [
    'playground' => (bool) config('mirror.features.playground', true),
    'admin' => (bool) config('mirror.features.admin', true),
    'help' => (bool) config('mirror.features.help', true),
    'web_proxy' => (bool) config('mirror.features.web_proxy', true),
    'root_aliases' => (bool) config('mirror.features.root_aliases', true),
];

$excludedFirstSegments = array_values(array_unique(array_filter([
    'api',
    '__codex-api',
    explode('/', $openaiPrefix)[0] ?? '',
    explode('/', $backendPrefix)[0] ?? '',
    explode('/', $adminPrefix)[0] ?? '',
    explode('/', $playgroundPrefix)[0] ?? '',
    explode('/', $helpPrefix)[0] ?? '',
    explode('/', $assetsPrefix)[0] ?? '',
    explode('/', $docsPrefix)[0] ?? '',
])));
$catchAllPattern = $excludedFirstSegments !== []
    ? '^(?!(?:' . implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $excludedFirstSegments)) . ')(?:/|$)).+'
    : '.+';

$registerWebRoutes = static function () use (
    $browserPrefix,
    $adminPrefix,
    $playgroundPrefix,
    $helpPrefix,
    $assetsPrefix,
    $catchAllPattern,
    $webExtraMiddleware,
    $adminExtraMiddleware,
    $features
): void {
    // 1. Built-in Playground UI & Static Assets
    if ($features['playground']) {
        Route::middleware($webExtraMiddleware)->group(function () use ($playgroundPrefix, $assetsPrefix, $features): void {
            Route::get('/' . $playgroundPrefix, [PlaygroundController::class, 'index'])->name('mirror.playground.index');
            Route::get('/' . $assetsPrefix . '/{file}', [PlaygroundController::class, 'asset'])
                ->where('file', '[A-Za-z0-9._-]+');

            if ($features['root_aliases'] && $playgroundPrefix !== 'playground') {
                Route::get('/playground', fn () => redirect('/' . $playgroundPrefix));
            }
        });
    }

    // 2. Settings & Admin Dashboard
    if ($features['admin']) {
        Route::middleware(array_merge($adminExtraMiddleware, [AuthorizeAdmin::class]))->group(function () use ($adminPrefix, $features): void {
            Route::prefix($adminPrefix)->group(function (): void {
                Route::get('/', [AdminController::class, 'index'])->name('mirror.admin.index');
                Route::middleware([ProtectControlEndpoints::class])->group(function (): void {
                    Route::post('/tokens', [AdminController::class, 'addToken']);
                    Route::post('/tokens/reset-counters', [AdminController::class, 'resetCounters']);
                    Route::post('/tokens/{id}/refresh', [AdminController::class, 'refreshToken']);
                    Route::post('/tokens/{id}/delete', [AdminController::class, 'deleteToken']);
                    Route::post('/runtime', [AdminController::class, 'saveRuntime']);
                    Route::post('/runtime/reset', [AdminController::class, 'resetRuntime']);
                    Route::post('/runtime/test-redis', [AdminController::class, 'testRedis']);
                    Route::get('/api/stats', [AdminController::class, 'stats']);
                });
            });

            if ($features['root_aliases']) {
                if ($adminPrefix !== 'admin') {
                    Route::get('/admin', fn () => redirect('/' . $adminPrefix));
                }
                if ($adminPrefix !== 'settings/mirror') {
                    Route::get('/settings/mirror', fn () => redirect('/' . $adminPrefix));
                }
            }
        });
    }

    // 2b. Help, Architecture & Configuration Guide
    if ($features['help']) {
        Route::middleware($webExtraMiddleware)->group(function () use ($helpPrefix, $features): void {
            Route::get('/' . $helpPrefix, [HelpController::class, 'index'])->name('mirror.help.index');

            if ($features['root_aliases']) {
                if ($helpPrefix !== 'help') {
                    Route::get('/help', fn () => redirect('/' . $helpPrefix));
                }
                if ($helpPrefix !== 'about') {
                    Route::get('/about', fn () => redirect('/' . $helpPrefix));
                }
                if ($helpPrefix !== 'mirror/about') {
                    Route::get('/mirror/about', fn () => redirect('/' . $helpPrefix));
                }
            }
        });
    }

    // 3 & 4. Explicit & Catch-All Proxied ChatGPT Web Frontend Paths
    if ($features['web_proxy']) {
        Route::middleware($webExtraMiddleware)->group(function () use ($browserPrefix, $catchAllPattern): void {
            Route::prefix($browserPrefix)->group(function () use ($browserPrefix, $catchAllPattern): void {
                Route::get('/', [BrowserProxyController::class, 'proxy']);
                Route::get('/c/{id}', [BrowserProxyController::class, 'proxy'])->where('id', '.*');
                Route::any('/_next/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
                Route::any('/cdn/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
                Route::any('/public-api/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
                Route::any('/ces/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
                Route::any('/assets/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
                Route::any('/fonts/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
                Route::any('/images/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');

                // Catch-All scoped strictly under browser_prefix (never hijacks host SaaS routes when prefixed!)
                Route::any('/{path}', [BrowserProxyController::class, 'proxy'])
                    ->where('path', $browserPrefix === '' ? $catchAllPattern : '.+');
            });
        });
    }
};

$outerGroup = Route::middleware([PrepareMirrorRuntime::class]);
if ($domain !== '') {
    $outerGroup->domain($domain);
}
$outerGroup->group($registerWebRoutes);
