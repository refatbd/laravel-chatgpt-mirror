<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\BrowserProxyController;

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
