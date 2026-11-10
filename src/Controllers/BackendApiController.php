<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BackendApiController extends Controller
{
    protected Client $http;

    public function __construct(
        protected TokenPoolManager $poolManager
    ) {
        $this->http = new Client([
            'base_uri' => config('mirror.upstream', 'https://chatgpt.com'),
            'timeout' => (float) config('mirror.egress.timeout', 90),
            'connect_timeout' => (float) config('mirror.egress.connect_timeout', 10),
            'verify' => (bool) config('mirror.egress.verify_ssl', true),
            'proxy' => config('mirror.egress.proxy'),
        ]);
    }

    /**
     * Synthesized auth session endpoint to prevent 'We couldn't load your account' bug.
     */
    public function session(Request $request): JsonResponse
    {
        $token = null;
        try {
            $token = $this->poolManager->acquire();
        } catch (Throwable) {
        }

        if (!$token) {
            return response()->json(['error' => 'No active token in pool'], 503);
        }

        $userEmail = $token->email ?: 'user@mirror.local';
        $userId = $token->account_id ?: 'user-mirror-default';
        $planType = $token->plan_type ?: 'free';

        $sessionData = [
            'user' => [
                'id' => $userId,
                'name' => $token->name ?: 'ChatGPT User',
                'email' => $userEmail,
                'image' => null,
                'picture' => null,
                'idp' => 'auth0',
                'iat' => time(),
                'mfa' => false,
                'groups' => [],
                'intercom_hash' => null,
            ],
            'expires' => now()->addDays(7)->toISOString(),
            'accessToken' => $token->access_token,
            'authProvider' => 'auth0',
            'account' => [
                'id' => $userId,
                'planType' => $planType,
                'structure' => 'personal',
                'isDelinquent' => false,
            ],
        ];

        $this->poolManager->release($token, true);

        return response()->json($sessionData);
    }

    /**
     * Proxy calls to /backend-api/* and /__codex-api/* with bearer authentication.
     */
    public function proxy(Request $request, string $path)
    {
        // Rewrite /__codex-api to /backend-api
        $cleanPath = preg_replace('#^__codex-api/?#', 'backend-api/', $path);
        if (!str_starts_with($cleanPath, 'backend-api/')) {
            $cleanPath = 'backend-api/' . ltrim($cleanPath, '/');
        }

        $token = null;
        try {
            $token = $this->poolManager->acquire();
        } catch (Throwable $e) {
            return response()->json(['error' => 'Token pool exhausted: ' . $e->getMessage()], 429);
        }

        $upstreamUrl = '/' . $cleanPath;
        $query = $request->getQueryString();
        if ($query) {
            $upstreamUrl .= '?' . $query;
        }

        $headers = [
            'Authorization' => "Bearer {$token->access_token}",
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Origin' => 'https://chatgpt.com',
            'Referer' => 'https://chatgpt.com/',
        ];

        if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
            $headers['Content-Type'] = $request->header('Content-Type', 'application/json');
        }

        $isStream = str_contains($request->header('Accept', ''), 'text/event-stream');

        if ($isStream) {
            return response()->stream(function () use ($upstreamUrl, $headers, $request, $token) {
                while (ob_get_level()) ob_end_clean();
                try {
                    $response = $this->http->request($request->method(), $upstreamUrl, [
                        'headers' => $headers,
                        'body' => $request->getContent(),
                        'stream' => true,
                        'http_errors' => false,
                    ]);

                    $body = $response->getBody();
                    while (!$body->eof()) {
                        echo $body->read(1024);
                        flush();
                    }
                } finally {
                    $this->poolManager->release($token, true);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        try {
            $response = $this->http->request($request->method(), $upstreamUrl, [
                'headers' => $headers,
                'body' => $request->getContent(),
                'http_errors' => false,
            ]);

            $this->poolManager->release($token, true);

            return response((string) $response->getBody(), $response->getStatusCode())
                ->header('Content-Type', $response->getHeaderLine('Content-Type'));
        } catch (GuzzleException $e) {
            $this->poolManager->release($token, false);
            return response()->json(['error' => 'Upstream request failed: ' . $e->getMessage()], 502);
        }
    }
}
