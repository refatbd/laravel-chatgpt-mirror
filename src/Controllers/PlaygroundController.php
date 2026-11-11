<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Models\MirrorSetting;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the React Playground SPA (`/mirror/playground`) and manages per-account
 * Playground preferences (`default-system-instructions`, `hotkeys`) in SQLite.
 */
class PlaygroundController extends Controller
{
    public function __construct(
        protected SecurityService $security,
        protected TokenPoolManager $pool
    ) {}

    public function index(Request $request): Response
    {
        $htmlPath = __DIR__ . '/../../resources/web/dist/index.html';
        if (!file_exists($htmlPath)) {
            return response('Mirror Playground frontend assets not built or found.', 404);
        }

        $html = (string) file_get_contents($htmlPath);
        $basePath = rtrim($this->security->resolveBasePath($request), '/');
        $assetsPrefix = trim((string) config('mirror.routes.assets_prefix', 'mirror/assets'), '/');
        $targetAssetBase = $basePath . '/' . $assetsPrefix . '/';
        if ($targetAssetBase !== '/mirror/assets/') {
            $html = str_replace('"/mirror/assets/', '"' . $targetAssetBase, $html);
        }

        $response = response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);

        return $this->security->attachControlCookie($response, $request);
    }

    public function asset(string $file): Response
    {
        $assetPath = realpath(__DIR__ . '/../../resources/web/dist/assets/' . basename($file));
        $baseDir = realpath(__DIR__ . '/../../resources/web/dist/assets');

        if (!$assetPath || !$baseDir || !str_starts_with($assetPath, $baseDir) || !file_exists($assetPath)) {
            return response('Asset not found', 404);
        }

        $ext = strtolower(pathinfo($assetPath, PATHINFO_EXTENSION));
        $mimeType = match ($ext) {
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'woff2' => 'font/woff2',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };

        return response((string) file_get_contents($assetPath), 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    public function getDefaultInstructions(): Response
    {
        $accountId = $this->pool->primaryAccountId();
        $content = MirrorSetting::getValue("default_system_instructions:{$accountId}", '');

        return response()->json([
            'content' => (string) $content,
        ]);
    }

    public function saveDefaultInstructions(Request $request): Response
    {
        $accountId = $this->pool->primaryAccountId();
        $content = (string) $request->input('content', '');
        MirrorSetting::setValue("default_system_instructions:{$accountId}", $content);

        return response()->json([
            'ok' => true,
            'content' => $content,
        ]);
    }

    public function getHotkeys(): Response
    {
        $accountId = $this->pool->primaryAccountId();
        $hotkeys = MirrorSetting::getJson("hotkeys:{$accountId}", []);

        return response()->json([
            'hotkeys' => $hotkeys !== [] ? $hotkeys : new \stdClass(),
        ]);
    }

    public function saveHotkeys(Request $request): Response
    {
        $accountId = $this->pool->primaryAccountId();
        $hotkeys = $request->input('hotkeys');
        if (!is_array($hotkeys)) {
            $hotkeys = [];
        }

        MirrorSetting::setJson("hotkeys:{$accountId}", $hotkeys);

        return response()->json([
            'ok' => true,
            'hotkeys' => $hotkeys !== [] ? $hotkeys : new \stdClass(),
        ]);
    }

    public function responses(Request $request): Response
    {
        return app(OpenAiApiController::class)->responses($request);
    }
}
