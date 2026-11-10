<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Models\MirrorConversation;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

class PlaygroundController extends Controller
{
    /**
     * Serve the Mirror API Playground SPA.
     */
    public function index(): Response
    {
        $indexPath = __DIR__ . '/../../resources/web/dist/index.html';
        if (!file_exists($indexPath)) {
            abort(404, 'Playground asset distribution not found.');
        }

        $html = file_get_contents($indexPath);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * Serve Playground static assets (.js, .css, etc.).
     */
    public function asset(string $file): Response
    {
        // Prevent directory traversal
        $safeFile = basename($file);
        $filePath = __DIR__ . '/../../resources/web/dist/assets/' . $safeFile;

        if (!file_exists($filePath)) {
            abort(404, 'Asset not found');
        }

        $ext = strtolower(pathinfo($safeFile, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'js' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return response(file_get_contents($filePath), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Return default system instructions.
     */
    public function getDefaultInstructions(): JsonResponse
    {
        $content = cache()->get('mirror:default_system_instructions', 'You are a helpful assistant.');

        return response()->json(['content' => $content]);
    }

    /**
     * Save default system instructions.
     */
    public function saveDefaultInstructions(Request $request): JsonResponse
    {
        $content = (string) $request->input('content', 'You are a helpful assistant.');
        cache()->put('mirror:default_system_instructions', $content, now()->addDays(30));

        return response()->json(['content' => $content]);
    }

    /**
     * Return keyboard shortcut settings.
     */
    public function getHotkeys(): JsonResponse
    {
        $hotkeys = cache()->get('mirror:user_hotkeys', []);

        return response()->json(['hotkeys' => (object) $hotkeys]);
    }

    /**
     * Save keyboard shortcut settings.
     */
    public function saveHotkeys(Request $request): JsonResponse
    {
        $hotkeys = $request->input('hotkeys', []);
        cache()->put('mirror:user_hotkeys', $hotkeys, now()->addDays(30));

        return response()->json(['hotkeys' => $hotkeys]);
    }

    /**
     * Return list of conversations for Playground sidebar.
     */
    public function conversations(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 50);
        $offset = (int) $request->input('offset', 0);

        $query = MirrorConversation::orderBy('updated_at', 'desc');
        $total = $query->count();
        $items = $query->skip($offset)->take($limit)->get()->map(function ($c) {
            return [
                'id' => $c->id,
                'title' => $c->title ?: 'Untitled Conversation',
                'model' => $c->model ?: 'auto',
                'updatedAt' => $c->updated_at?->toISOString() ?? now()->toISOString(),
            ];
        });

        return response()->json([
            'items' => $items,
            'total' => $total,
            'hasMore' => ($offset + count($items)) < $total,
        ]);
    }

    /**
     * Return single conversation detail.
     */
    public function conversation(string $id): JsonResponse
    {
        $conv = MirrorConversation::find($id);

        return response()->json([
            'conversation' => $conv ? [
                'id' => $conv->id,
                'title' => $conv->title,
                'model' => $conv->model,
            ] : [
                'id' => $id,
                'title' => 'Chat Session',
                'model' => 'auto',
            ],
            'messages' => [],
            'instructions' => null,
        ]);
    }

    /**
     * Convert /v1/responses format to /v1/chat/completions format.
     */
    public function responses(Request $request, OpenAiApiController $openAiController)
    {
        $input = $request->input('input');
        $instructions = $request->input('instructions');
        $model = $request->input('model', 'auto');
        $stream = (bool) $request->input('stream', false);

        $messages = [];
        if (!empty($instructions)) {
            $messages[] = ['role' => 'system', 'content' => (string) $instructions];
        }

        if (is_string($input)) {
            $messages[] = ['role' => 'user', 'content' => $input];
        } elseif (is_array($input)) {
            foreach ($input as $item) {
                if (is_array($item)) {
                    $content = $item['content'] ?? '';
                    if (is_array($content)) {
                        $text = '';
                        foreach ($content as $part) {
                            $text .= $part['text'] ?? '';
                        }
                        $content = $text;
                    }
                    $messages[] = [
                        'role' => $item['role'] ?? 'user',
                        'content' => (string) $content,
                    ];
                }
            }
        }

        // Synthesize request for chatCompletions
        $synthesizedRequest = Request::create('/v1/chat/completions', 'POST', [
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ], [], [], $request->server->all(), json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ]));
        $synthesizedRequest->headers->replace($request->headers->all());

        return $openAiController->chatCompletions($synthesizedRequest);
    }
}
