<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\BackendApiController;
use Refatbd\LaravelChatGptMirror\Controllers\OpenAiApiController;
use Refatbd\LaravelChatGptMirror\Http\Middleware\ValidateApiKey;

$openaiPrefix = config('mirror.routes.openai_prefix', 'v1');
$backendPrefix = config('mirror.routes.backend_prefix', 'backend-api');

// 1. OpenAI-compatible API Endpoints
Route::prefix($openaiPrefix)
    ->middleware([ValidateApiKey::class])
    ->group(function () {
        Route::get('/models', [OpenAiApiController::class, 'models']);
        Route::post('/chat/completions', [OpenAiApiController::class, 'chatCompletions']);
    });

// 2. Synthesized NextAuth Session for ChatGPT Web UI
Route::get('/api/auth/session', [BackendApiController::class, 'session']);

// 3. Proxied Backend Endpoints
Route::any('/' . ltrim($backendPrefix, '/') . '/{path}', [BackendApiController::class, 'proxy'])
    ->where('path', '.*');

Route::any('/__codex-api/{path}', [BackendApiController::class, 'proxy'])
    ->where('path', '.*');
