<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\BrowserProxyController;

$browserPrefix = config('mirror.routes.browser_prefix', 'mirror');

Route::prefix($browserPrefix)->group(function () {
    Route::get('/{path?}', [BrowserProxyController::class, 'proxy'])->where('path', '.*');
});
