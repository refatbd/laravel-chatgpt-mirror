<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\AdminController;
use Refatbd\LaravelChatGptMirror\Controllers\BrowserProxyController;
use Refatbd\LaravelChatGptMirror\Controllers\HelpController;
use Refatbd\LaravelChatGptMirror\Controllers\PlaygroundController;
use Refatbd\LaravelChatGptMirror\Http\Middleware\PrepareMirrorRuntime;
use Refatbd\LaravelChatGptMirror\Http\Middleware\ProtectControlEndpoints;

$browserPrefix = trim((string) config('mirror.routes.browser_prefix', ''), '/');
$adminPrefix = trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/');
$playgroundPrefix = trim((string) config('mirror.routes.playground_prefix', 'mirror/playground'), '/');
$helpPrefix = trim((string) config('mirror.routes.help_prefix', 'mirror/help'), '/');
$assetsPrefix = trim((string) config('mirror.routes.assets_prefix', 'mirror/assets'), '/');
$openaiPrefix = trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');
$backendPrefix = trim((string) config('mirror.routes.backend_prefix', 'backend-api'), '/');
$docsPrefix = trim((string) config('mirror.routes.docs_prefix', 'mirror'), '/');

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

Route::middleware([PrepareMirrorRuntime::class])->group(function () use ($browserPrefix, $adminPrefix, $playgroundPrefix, $helpPrefix, $assetsPrefix, $catchAllPattern): void {

// 1. Built-in Playground UI & Static Assets
Route::get('/' . $playgroundPrefix, [PlaygroundController::class, 'index']);
Route::get('/playground', fn () => redirect('/' . $playgroundPrefix));
Route::get('/' . $assetsPrefix . '/{file}', [PlaygroundController::class, 'asset'])
    ->where('file', '[A-Za-z0-9._-]+');

// 2. Settings & Admin Dashboard
Route::prefix($adminPrefix)->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('mirror.admin.index');
    Route::middleware([ProtectControlEndpoints::class])->group(function () {
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

// Friendly Settings / Admin aliases
Route::get('/admin', fn () => redirect('/' . $adminPrefix));
Route::get('/settings/mirror', fn () => redirect('/' . $adminPrefix));

// 2b. Help, Architecture & Configuration Guide
Route::get('/' . $helpPrefix, [HelpController::class, 'index'])->name('mirror.help.index');
if ($helpPrefix !== 'help') {
    Route::get('/help', fn () => redirect('/' . $helpPrefix));
}
if ($helpPrefix !== 'about') {
    Route::get('/about', fn () => redirect('/' . $helpPrefix));
}
if ($helpPrefix !== 'mirror/about') {
    Route::get('/mirror/about', fn () => redirect('/' . $helpPrefix));
}

// 3. Explicit Proxied ChatGPT Web Frontend Paths
Route::prefix($browserPrefix)->group(function () {
    Route::get('/', [BrowserProxyController::class, 'proxy']);
    Route::get('/c/{id}', [BrowserProxyController::class, 'proxy'])->where('id', '.*');
    Route::any('/_next/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
    Route::any('/cdn/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
    Route::any('/public-api/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
    Route::any('/ces/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
    Route::any('/assets/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
    Route::any('/fonts/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
    Route::any('/images/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
});

// 4. Catch-All Browser Proxy Fallback (dynamically skips all configured Mirror prefixes)
Route::any('/{path}', [BrowserProxyController::class, 'proxy'])
    ->where('path', $catchAllPattern);

});
