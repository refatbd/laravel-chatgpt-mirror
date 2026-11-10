<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\AdminController;
use Refatbd\LaravelChatGptMirror\Controllers\BrowserProxyController;

// Admin Dashboard, Settings & Concurrency Pool Manager
Route::prefix('mirror/admin')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('mirror.admin.index');
    Route::post('/tokens/add', [AdminController::class, 'addToken'])->name('mirror.admin.tokens.add');
    Route::post('/tokens/{id}/refresh', [AdminController::class, 'refreshToken'])->name('mirror.admin.tokens.refresh');
    Route::post('/tokens/{id}/delete', [AdminController::class, 'deleteToken'])->name('mirror.admin.tokens.delete');
    Route::get('/api/stats', [AdminController::class, 'stats'])->name('mirror.admin.stats');
});

// Settings & Admin navigation aliases
Route::get('/admin', fn () => redirect('/mirror/admin'));
Route::get('/settings/mirror', fn () => redirect('/mirror/admin'));

$browserPrefix = config('mirror.routes.browser_prefix', 'mirror');

Route::prefix($browserPrefix)->group(function () {
    Route::any('/{path?}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
});

// Root & direct browser routes
Route::get('/', [BrowserProxyController::class, 'proxy']);
Route::get('/c/{id}', [BrowserProxyController::class, 'proxy']);
Route::any('/_next/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
Route::any('/cdn/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
Route::any('/public-api/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
Route::any('/ces/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
Route::any('/assets/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
Route::any('/fonts/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
Route::any('/images/{path}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');

