<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\AdminController;
use Refatbd\LaravelChatGptMirror\Controllers\BrowserProxyController;
use Refatbd\LaravelChatGptMirror\Controllers\PlaygroundController;
use Refatbd\LaravelChatGptMirror\Http\Middleware\ProtectControlEndpoints;

$browserPrefix = trim((string) config('mirror.routes.browser_prefix', ''), '/');
$adminPrefix = trim((string) config('mirror.routes.admin_prefix', 'mirror/admin'), '/');

// 1. Built-in Playground UI & Static Assets
Route::get('/mirror/playground', [PlaygroundController::class, 'index']);
Route::get('/playground', fn () => redirect('/mirror/playground'));
Route::get('/mirror/assets/{file}', [PlaygroundController::class, 'asset'])
    ->where('file', '[A-Za-z0-9._-]+');

// 2. Settings & Admin Dashboard
Route::prefix($adminPrefix)->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('mirror.admin.index');
    Route::middleware([ProtectControlEndpoints::class])->group(function () {
        Route::post('/tokens', [AdminController::class, 'addToken']);
        Route::post('/tokens/reset-counters', [AdminController::class, 'resetCounters']);
        Route::post('/tokens/{id}/refresh', [AdminController::class, 'refreshToken']);
        Route::post('/tokens/{id}/delete', [AdminController::class, 'deleteToken']);
        Route::get('/api/stats', [AdminController::class, 'stats']);
    });
});

// Friendly Settings / Admin aliases
Route::get('/admin', fn () => redirect('/' . $adminPrefix));
Route::get('/settings/mirror', fn () => redirect('/' . $adminPrefix));

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

// 4. Catch-All Browser Proxy Fallback (for /sentinel/*, /g/*, /share/*, /realtime/*, unknown ChatGPT frontend routes)
Route::any('/{path}', [BrowserProxyController::class, 'proxy'])
    ->where('path', '^(?!api(?:/|$)|v1(?:/|$)|mirror(?:/|$)|backend-api(?:/|$)|__codex-api(?:/|$)).+');
