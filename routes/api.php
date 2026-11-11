<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Refatbd\LaravelChatGptMirror\Controllers\AdminController;
use Refatbd\LaravelChatGptMirror\Controllers\ApiDocsController;
use Refatbd\LaravelChatGptMirror\Controllers\BackendApiController;
use Refatbd\LaravelChatGptMirror\Controllers\ConversionController;
use Refatbd\LaravelChatGptMirror\Controllers\DecoderChallengeController;
use Refatbd\LaravelChatGptMirror\Controllers\MirrorApiController;
use Refatbd\LaravelChatGptMirror\Controllers\OpenAiApiController;
use Refatbd\LaravelChatGptMirror\Controllers\PlaygroundController;
use Refatbd\LaravelChatGptMirror\Http\Middleware\AuthorizeAdmin;
use Refatbd\LaravelChatGptMirror\Http\Middleware\PrepareMirrorRuntime;
use Refatbd\LaravelChatGptMirror\Http\Middleware\ProtectControlEndpoints;
use Refatbd\LaravelChatGptMirror\Http\Middleware\ValidateApiKey;

if (!config('mirror.enabled', true)) {
    return;
}

$openaiPrefix = trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');
$backendPrefix = trim((string) config('mirror.routes.backend_prefix', 'backend-api'), '/');
$docsPrefix = trim((string) config('mirror.routes.docs_prefix', 'mirror'), '/');

$domain = trim((string) (config('mirror.routes.domain') ?? ''));
$apiExtraMiddleware = array_values(array_filter((array) config('mirror.routes.api_middleware', [])));
$webExtraMiddleware = array_values(array_filter((array) config('mirror.routes.web_middleware', [])));
$adminExtraMiddleware = array_values(array_filter((array) config('mirror.routes.admin_middleware', [])));

$features = [
    'openai_api' => (bool) config('mirror.features.openai_api', true),
    'mirror_api' => (bool) config('mirror.features.mirror_api', true),
    'backend_proxy' => (bool) config('mirror.features.backend_proxy', true),
    'docs' => (bool) config('mirror.features.docs', true),
    'admin' => (bool) config('mirror.features.admin', true),
];

$registerApiRoutes = static function () use (
    $openaiPrefix,
    $backendPrefix,
    $docsPrefix,
    $apiExtraMiddleware,
    $webExtraMiddleware,
    $adminExtraMiddleware,
    $features
): void {
    // 1. OpenAI-compatible Gateway (/v1/*)
    if ($features['openai_api']) {
        Route::middleware($apiExtraMiddleware)->group(function () use ($openaiPrefix): void {
            Route::prefix($openaiPrefix)->group(function (): void {
                Route::get('/models', [OpenAiApiController::class, 'models'])->middleware([ValidateApiKey::class]);
                Route::post('/chat/completions', [OpenAiApiController::class, 'chatCompletions'])->middleware([ValidateApiKey::class]);
                Route::post('/responses', [OpenAiApiController::class, 'responses'])->middleware([ValidateApiKey::class]);
                Route::get('/capabilities', [AdminController::class, 'capabilities']);
            });

            Route::options('/' . $openaiPrefix . '/{any?}', fn () => response('', 204))->where('any', '.*');
        });
    }

    if ($features['openai_api'] || $features['mirror_api']) {
        Route::get('/api/health', [AdminController::class, 'health']);
    }

    // 2. Synthesized NextAuth Session for ChatGPT Web UI (never leaks raw upstream JWT)
    if ($features['backend_proxy']) {
        Route::get('/api/auth/session', [BackendApiController::class, 'session']);
    }

    // 3. Mirror Admin Control & Runtime Settings APIs (protected by control auth + AuthorizeAdmin gate)
    if ($features['mirror_api'] || $features['admin']) {
        Route::middleware(array_merge($adminExtraMiddleware, [AuthorizeAdmin::class, ProtectControlEndpoints::class]))->group(function (): void {
            Route::get('/api/diagnostics', [AdminController::class, 'diagnostics']);
            Route::get('/api/session', [AdminController::class, 'getSession']);
            Route::post('/api/session', [AdminController::class, 'postSession']);
            Route::delete('/api/session', [AdminController::class, 'deleteSession']);

            Route::get('/api/settings/runtime', [AdminController::class, 'getRuntimeSettings']);
            Route::put('/api/settings/runtime', [AdminController::class, 'putRuntimeSettings']);
            Route::post('/api/settings/runtime/test-redis', [AdminController::class, 'testRedis']);
            Route::delete('/api/settings/runtime', [AdminController::class, 'resetRuntimeSettings']);
        });
    }

    // 4. Native Mirror, Playground Preferences, Conversation, File, Conversion & Challenge APIs
    if ($features['mirror_api']) {
        Route::match(['GET', 'HEAD'], '/api/asset-content', [MirrorApiController::class, 'assetContent']);
        Route::options('/api/{any?}', fn () => response('', 204))->where('any', '.*');

        Route::middleware(array_merge($apiExtraMiddleware, [ProtectControlEndpoints::class]))->group(function (): void {
            Route::get('/api/settings/default-system-instructions', [PlaygroundController::class, 'getDefaultInstructions']);
            Route::put('/api/settings/default-system-instructions', [PlaygroundController::class, 'saveDefaultInstructions']);
            Route::get('/api/settings/hotkeys', [PlaygroundController::class, 'getHotkeys']);
            Route::put('/api/settings/hotkeys', [PlaygroundController::class, 'saveHotkeys']);

            Route::get('/api/models', [MirrorApiController::class, 'models']);
            Route::get('/api/gpts', [MirrorApiController::class, 'gpts']);
            Route::post('/api/chat', [MirrorApiController::class, 'chat']);

            // Note: /search must be registered BEFORE /api/conversations/{id}!
            Route::get('/api/conversations', [MirrorApiController::class, 'conversations']);
            Route::post('/api/conversations', [MirrorApiController::class, 'createConversation']);
            Route::get('/api/conversations/search', [MirrorApiController::class, 'searchConversations']);
            Route::get('/api/conversations/{id}', [MirrorApiController::class, 'showConversation']);
            Route::patch('/api/conversations/{id}', [MirrorApiController::class, 'updateConversation']);
            Route::delete('/api/conversations/{id}', [MirrorApiController::class, 'deleteConversation']);
            Route::post('/api/conversations/{id}/stop', [MirrorApiController::class, 'stopConversation']);
            Route::post('/api/conversations/{id}/branch', [MirrorApiController::class, 'branchConversation']);
            Route::get('/api/conversations/{id}/branches', [MirrorApiController::class, 'branches']);
            Route::get('/api/conversations/{id}/export', [MirrorApiController::class, 'exportConversation']);

            Route::post('/api/files', [MirrorApiController::class, 'uploadFile']);
            Route::get('/api/assets', [MirrorApiController::class, 'assets']);

            // File-conversion codecs & Playground Convert tab
            Route::get('/api/convert/formats', [ConversionController::class, 'formats']);
            Route::post('/api/convert/loaf/encode', [ConversionController::class, 'loafEncode']);
            Route::post('/api/convert/loaf/decode', [ConversionController::class, 'loafDecode']);
            Route::post('/api/convert/loaf/verify', [ConversionController::class, 'loafVerify']);
            Route::post('/api/convert/pngspeak/encode', [ConversionController::class, 'pngspeakEncode']);
            Route::post('/api/convert/pngspeak/decode', [ConversionController::class, 'pngspeakDecode']);
            Route::post('/api/convert/gptgif/encode', [ConversionController::class, 'gptgifEncode']);
            Route::post('/api/convert/gptgif/decode', [ConversionController::class, 'gptgifDecode']);
            Route::post('/api/convert/gptgif/calibrate', [ConversionController::class, 'gptgifCalibrate']);
            Route::get('/api/convert/gptgif/font-preview', [ConversionController::class, 'gptgifFontPreview']);
            Route::post('/api/convert/gptgif-v4/encode', [ConversionController::class, 'gptgifV4Encode']);
            Route::post('/api/convert/gptgif-v4/decode', [ConversionController::class, 'gptgifV4Decode']);
            Route::get('/api/convert/gptgif-v4/random-style', [ConversionController::class, 'gptgifV4RandomStyle']);
            Route::get('/api/convert/gptgif-v4/default-style', [ConversionController::class, 'gptgifV4DefaultStyle']);
            Route::post('/api/convert/gptgif-v4/font/validate', [ConversionController::class, 'gptgifV4ValidateFont']);
            Route::post('/api/convert/gptgif-v4/palette/validate', [ConversionController::class, 'gptgifV4ValidatePalette']);
            Route::post('/api/convert/gif89a/encode', [ConversionController::class, 'gif89aEncode']);
            Route::post('/api/convert/gif89a/inspect', [ConversionController::class, 'gif89aInspect']);
            Route::post('/api/convert/roundtrip', [ConversionController::class, 'roundtrip']);
            Route::post('/api/convert/onion/encode', [ConversionController::class, 'onionEncode']);
            Route::post('/api/convert/onion/decode', [ConversionController::class, 'onionDecode']);
            Route::post('/api/convert/gpt-prompt', [ConversionController::class, 'gptPrompt']);
            Route::get('/api/convert/mystery', [ConversionController::class, 'mystery']);
            Route::get('/api/convert/fortune', [ConversionController::class, 'fortune']);
            Route::post('/api/convert/gauntlet', [ConversionController::class, 'gauntlet']);

            // Decoder challenges
            Route::post('/api/decoder-challenges', [DecoderChallengeController::class, 'create']);
            Route::post('/api/decoder-challenges/kit', [DecoderChallengeController::class, 'kit']);
            Route::get('/api/decoder-challenges/{id}/artifact', [DecoderChallengeController::class, 'artifact']);
            Route::post('/api/decoder-challenges/{id}/verify', [DecoderChallengeController::class, 'verify']);
        });
    }

    // 5. OpenAPI Schema & Interactive Swagger UI Docs
    if ($features['docs']) {
        Route::middleware($webExtraMiddleware)->group(function () use ($docsPrefix): void {
            $docsBase = $docsPrefix !== '' ? ('/' . $docsPrefix) : '';
            Route::get($docsBase . '/openapi', [ApiDocsController::class, 'openapi']);
            Route::get($docsBase . '/api-docs', [ApiDocsController::class, 'ui']);
            Route::get($docsBase . '/api-docs/{file}', [ApiDocsController::class, 'asset'])
                ->where('file', '[A-Za-z0-9._-]+');
        });
    }

    // 6. Proxied ChatGPT Backend Endpoints
    if ($features['backend_proxy']) {
        Route::any('/' . $backendPrefix . '/{path}', [BackendApiController::class, 'proxy'])
            ->where('path', '.*');

        Route::any('/__codex-api/{path}', [BackendApiController::class, 'proxy'])
            ->where('path', '.*');
    }
};

$outerGroup = Route::middleware([PrepareMirrorRuntime::class]);
if ($domain !== '') {
    $outerGroup->domain($domain);
}
$outerGroup->group($registerApiRoutes);
