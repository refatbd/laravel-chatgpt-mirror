<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\AdminController;
use Refatbd\LaravelChatGptMirror\Controllers\BackendApiController;
use Refatbd\LaravelChatGptMirror\Controllers\OpenAiApiController;
use Refatbd\LaravelChatGptMirror\Http\Middleware\ValidateApiKey;

$openaiPrefix = config('mirror.routes.openai_prefix', 'v1');
$backendPrefix = config('mirror.routes.backend_prefix', 'backend-api');

// 1. OpenAI-compatible API Endpoints
Route::prefix($openaiPrefix)
    ->group(function () {
        Route::get('/models', [OpenAiApiController::class, 'models'])->middleware([ValidateApiKey::class]);
        Route::post('/chat/completions', [OpenAiApiController::class, 'chatCompletions'])->middleware([ValidateApiKey::class]);
        Route::get('/capabilities', [AdminController::class, 'capabilities']);
    });

// 2. Health, Diagnostics & Programmatic Session Management (Node.js mirror compatibility)
Route::get('/api/health', [AdminController::class, 'health']);
Route::get('/api/diagnostics', [AdminController::class, 'diagnostics']);
Route::get('/api/session', [AdminController::class, 'getSession']);
Route::post('/api/session', [AdminController::class, 'postSession']);
Route::delete('/api/session', [AdminController::class, 'deleteSession']);

// 3. Synthesized NextAuth Session for ChatGPT Web UI
Route::get('/api/auth/session', [BackendApiController::class, 'session']);

// 4. OpenAPI schema
Route::get('/mirror/openapi', [AdminController::class, 'capabilities']);

// 5. Proxied Backend Endpoints
Route::any('/' . ltrim($backendPrefix, '/') . '/{path}', [BackendApiController::class, 'proxy'])
    ->where('path', '.*');

Route::any('/__codex-api/{path}', [BackendApiController::class, 'proxy'])
    ->where('path', '.*');
