<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxies ChatGPT Web backend API requests (/backend-api/*, /__codex-api/*, /api/auth/session)
 * cleanly without exposing real OpenAI access tokens to browser JavaScript.
 */
class BackendApiController extends Controller
{
    protected Client $http;

    public function __construct(
        protected TokenPoolManager $pool,
        protected ChatGptClient $client,
        protected BrowserPatchManager $patcher
    ) {
        $this->http = new Client([
            'base_uri' => rtrim((string) config('mirror.upstream', 'https://chatgpt.com'), '/'),
            'timeout' => (float) config('mirror.http.timeout', 120),
            'connect_timeout' => (float) config('mirror.http.connect_timeout', 15),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
        ]);
    }

    /**
     * Synthesizes NextAuth `/api/auth/session` for ChatGPT Web UI without leaking raw JWTs.
     */
    public function session(): Response
    {
        $token = $this->pool->acquire();
        if (!$token) {
            return response()->json([
                'user' => null,
                'expires' => null,
            ], 200);
        }

        try {
            return response()->json([
                'user' => [
                    'id' => $token->account_id ?: 'mirror-user',
                    'name' => $token->name ?: 'Mirror User',
                    'email' => $token->email ?: 'user@mirror.local',
                    'image' => null,
                    'picture' => null,
                ],
                'expires' => now()->addHours(12)->toIso8601String(),
                'accessToken' => UrlRewriter::BROWSER_TOKEN,
                'authProvider' => 'openai',
                'account' => [
                    'id' => $token->account_id ?: 'mirror-account',
                    'planType' => $token->plan_type ?: 'free',
                    'structure' => 'personal',
                ],
            ]);
        } finally {
            $this->pool->release($token, true);
        }
    }

    /**
     * Transparent proxy for `/backend-api/{path}` and `/__codex-api/{path}` with streaming support.
     */
    public function proxy(Request $request, string $path = ''): Response
    {
        $token = $this->pool->acquire();
        if (!$token) {
            return response()->json(['detail' => 'No active ChatGPT session token in pool'], 503);
        }

        $targetPath = '/backend-api/' . ltrim($path, '/');
        $query = $request->getQueryString();
        if ($query) {
            $targetPath .= '?' . $query;
        }

        $headers = $this->client->commonHeaders($token, [
            'Accept' => (string) $request->header('Accept', 'application/json, text/event-stream, */*'),
        ]);

        // Forward ChatGPT web client specific headers safely while replacing Authorization.
        foreach ([
            'openai-sentinel-chat-requirements-token',
            'openai-sentinel-proof-token',
            'openai-sentinel-turnstile-token',
            'x-conduit-token',
            'x-oai-turn-trace-id',
            'oai-device-id',
        ] as $headerName) {
            $val = $request->header($headerName);
            if ($val) {
                $headers[$headerName] = $val;
            }
        }

        if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
            $headers['Content-Type'] = (string) $request->header('Content-Type', 'application/json');
        }

        if ($token->session_token) {
            $headers['Cookie'] = '__Secure-next-auth.session-token=' . $token->session_token;
        }

        $acceptStream = str_contains((string) $request->header('Accept', ''), 'text/event-stream')
            || str_contains($path, 'conversation');
        $isBackgroundPoll = str_contains($path, 'celsius/ws') || str_contains($path, 'ws/user');

        try {
            $options = [
                'headers' => $headers,
                'stream' => $acceptStream,
                'connect_timeout' => 5,
            ];
            if ($isBackgroundPoll) {
                $options['timeout'] = 5;
            } elseif (!$acceptStream) {
                $options['timeout'] = 15;
            }

            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
                $options['body'] = $request->getContent();
            }

            $upstream = $this->http->request($request->method(), $targetPath, $options);
            $status = $upstream->getStatusCode();

            if ($status === 429) {
                $this->pool->putInCooldown($token);
            }

            $rawHeaders = [];
            foreach ($upstream->getHeaders() as $k => $vals) {
                $rawHeaders[$k] = $vals[0] ?? '';
            }
            $cleanedHeaders = $this->patcher->cleanHeaders($rawHeaders);
            $contentType = (string) ($cleanedHeaders['Content-Type'] ?? $cleanedHeaders['content-type'] ?? 'application/json');

            if (stripos($contentType, 'text/event-stream') !== false) {
                $pool = $this->pool;
                return response()->stream(function () use ($upstream, $token, $pool, $status): void {
                    $body = $upstream->getBody();
                    while (!$body->eof()) {
                        echo $body->read(1024);
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        @flush();
                    }
                    $pool->release($token, $status < 400);
                }, $status, [
                    'Content-Type' => 'text/event-stream; charset=utf-8',
                    'Cache-Control' => 'no-cache, no-transform',
                    'X-Accel-Buffering' => 'no',
                ]);
            }

            $bodyStr = (string) $upstream->getBody();
            $this->pool->release($token, $status < 400);

            return response($bodyStr, $status, $cleanedHeaders);
        } catch (\Throwable $e) {
            $this->pool->release($token, false);
            return response()->json([
                'error' => 'Backend Proxy Error: ' . $e->getMessage(),
            ], 502);
        }
    }
}
