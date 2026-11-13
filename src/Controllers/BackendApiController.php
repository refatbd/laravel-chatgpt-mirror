<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Controllers\Concerns\ResolvesWebProxyContext;
use Refatbd\LaravelChatGptMirror\Mirror;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxies ChatGPT Web backend API requests (/backend-api/*, /__codex-api/*, /api/auth/session)
 * cleanly without exposing real OpenAI access tokens to browser JavaScript, and exposes
 * SaaS hooks for identity masking, pre-request gating, response filtering, and live SSE accounting.
 */
class BackendApiController extends Controller
{
    use ResolvesWebProxyContext;

    protected Client $http;
    protected SecurityService $security;

    public function __construct(
        protected TokenPoolManager $pool,
        protected ChatGptClient $client,
        protected BrowserPatchManager $patcher,
        ?Client $http = null,
        ?SecurityService $security = null
    ) {
        $this->http = $http ?? new Client([
            'base_uri' => rtrim((string) config('mirror.upstream', 'https://chatgpt.com'), '/'),
            'timeout' => (float) config('mirror.http.timeout', 120),
            'connect_timeout' => (float) config('mirror.http.connect_timeout', 15),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
        ]);
        $this->security = $security ?? (app()->bound(SecurityService::class) ? app(SecurityService::class) : new SecurityService());
    }

    /**
     * Synthesizes NextAuth `/api/auth/session` for ChatGPT Web UI without leaking raw JWTs.
     */
    public function session(Request $request): Response
    {
        $guard = Mirror::resolveProxyRequestGuard();
        if ($guard !== null && $guard($request) === false) {
            abort(404);
        }

        $ctx = $this->resolveWebProxyContext($request);
        if ($ctx['short_circuit'] instanceof Response) {
            return $ctx['short_circuit'];
        }

        $token = $ctx['token'];
        if (!$token && empty($ctx['skip_token_pool'])) {
            return response()->json([
                'user' => null,
                'expires' => null,
            ], 200);
        }

        try {
            $payload = [
                'user' => $ctx['session_user'] ?? [
                    'id' => $token?->account_id ?: 'mirror-user',
                    'name' => $token?->name ?: 'Mirror User',
                    'email' => $token?->email ?: 'user@mirror.local',
                    'image' => null,
                    'picture' => null,
                ],
                'expires' => now()->addHours(12)->toIso8601String(),
                'accessToken' => UrlRewriter::BROWSER_TOKEN,
                'authProvider' => 'openai',
                'account' => $ctx['session_account'] ?? [
                    'id' => $token?->account_id ?: 'mirror-account',
                    'planType' => $token?->plan_type ?: 'free',
                    'structure' => 'personal',
                ],
            ];

            $customizer = Mirror::resolveBrowserSessionCustomizer();
            if ($customizer !== null) {
                $customized = $customizer($payload, $request, $token, $ctx);
                if (is_array($customized)) {
                    $payload = $customized;
                }
            }

            return response()->json($payload);
        } finally {
            $this->releaseWebProxyContext($ctx, true);
        }
    }

    /**
     * Transparent proxy for `/backend-api/{path}` requests with streaming & SaaS hooks.
     */
    public function proxy(Request $request, string $path = ''): Response
    {
        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'public-api/')) {
            return $this->dispatchProxy($request, '/' . $clean, $path);
        }

        $prefix = str_starts_with(ltrim($request->path(), '/'), '__codex-api')
            ? '/__codex-api/'
            : '/backend-api/';

        return $this->dispatchProxy($request, $prefix . $clean, $path);
    }

    /**
     * Transparent proxy for `/__codex-api/{path}` requests.
     */
    public function codexProxy(Request $request, string $path = ''): Response
    {
        return $this->dispatchProxy($request, '/__codex-api/' . ltrim($path, '/'), $path);
    }

    /**
     * Transparent proxy for `/public-api/{path}` requests (e.g. Gizmo/GPT discovery feeds).
     */
    public function publicApiProxy(Request $request, string $path = ''): Response
    {
        return $this->dispatchProxy($request, '/public-api/' . ltrim($path, '/'), $path);
    }

    /**
     * Transparent proxy for upstream `/api/{path}` endpoints (such as `/api/library/files/{id}/content`).
     */
    public function apiProxy(Request $request, string $path = ''): Response
    {
        $clean = ltrim($path, '/');
        // If client requests /thumbnail or /libfile on a library file, transparently serve /content
        if (preg_match('#^library/files/([^/]+)/(?:thumbnail|libfile)$#', $clean, $m)) {
            $clean = "library/files/{$m[1]}/content";
        }

        return $this->dispatchProxy($request, '/api/' . $clean, $path);
    }

    /**
     * Dispatches transparent proxy requests to upstream OpenAI / ChatGPT endpoint.
     */
    public function dispatchProxy(Request $request, string $targetPath, string $path = ''): Response
    {
        $guard = Mirror::resolveProxyRequestGuard();
        if ($guard !== null && $guard($request) === false) {
            abort(404);
        }

        $ctx = $this->resolveWebProxyContext($request);
        if ($ctx['short_circuit'] instanceof Response) {
            return $ctx['short_circuit'];
        }

        $token = $ctx['token'];
        if (!$token && empty($ctx['skip_token_pool'])) {
            return response()->json(['detail' => 'No active ChatGPT session token in pool'], 503);
        }

        $released = false;
        $releaseOnce = function (bool $success) use ($ctx, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $this->releaseWebProxyContext($ctx, $success);
        };

        try {
            $interceptor = Mirror::resolveBackendRequestInterceptor();
            if ($interceptor !== null) {
                $intercepted = $interceptor($request, $path, $ctx);
                if ($intercepted instanceof Response) {
                    $releaseOnce(false);
                    return $intercepted;
                }
            }

            $query = $request->getQueryString();
            if ($query) {
                $targetPath .= (str_contains($targetPath, '?') ? '&' : '?') . $query;
            }

            $remoteBase = $ctx['upstream_url'];
            $requestUrl = $remoteBase ? ($remoteBase . $targetPath) : $targetPath;

            if ($token instanceof MirrorToken) {
                $headers = $this->client->commonHeaders($token, [
                    'Accept' => (string) $request->header('Accept', 'application/json, text/event-stream, */*'),
                ]);
                if ($token->session_token) {
                    $headers['Cookie'] = '__Secure-next-auth.session-token=' . $token->session_token;
                }
            } else {
                $headers = [
                    'User-Agent' => (string) config('mirror.http.user_agent', $request->userAgent() ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
                    'Accept' => (string) $request->header('Accept', 'application/json, text/event-stream, */*'),
                    'Accept-Language' => 'en-US,en;q=0.9',
                ];
            }

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

            foreach ($ctx['upstream_headers'] as $hk => $hv) {
                $headers[$hk] = $hv;
            }

            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
                $headers['Content-Type'] = (string) $request->header('Content-Type', 'application/json');
            }

            $acceptStream = str_contains((string) $request->header('Accept', ''), 'text/event-stream')
                || str_contains($path, 'conversation');
            $isBackgroundPoll = str_contains($path, 'celsius/ws') || str_contains($path, 'ws/user');

            $options = [
                'headers' => $headers,
                'stream' => $acceptStream,
                'connect_timeout' => (float) config('mirror.http.connect_timeout', 5),
                'verify' => (bool) config('mirror.http.verify_ssl', true),
            ];
            $proxyCfg = $ctx['http_proxy'] ?? config('mirror.http.proxy');
            if (is_string($proxyCfg) && trim($proxyCfg) !== '') {
                $options['proxy'] = trim($proxyCfg);
            }

            if ($isBackgroundPoll) {
                $options['timeout'] = 5;
            } elseif (!$acceptStream) {
                $options['timeout'] = (float) config('mirror.http.timeout', 15);
            } else {
                $options['read_timeout'] = (float) config('mirror.http.read_timeout', 120);
            }

            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
                $options['body'] = $request->getContent();
            }

            $upstream = $this->http->request($request->method(), $requestUrl, $options);
            $status = $upstream->getStatusCode();

            if ($status === 429 && $token instanceof MirrorToken) {
                $this->pool->putInCooldown($token);
            }

            $rawHeaders = [];
            foreach ($upstream->getHeaders() as $k => $vals) {
                $rawHeaders[$k] = $vals[0] ?? '';
            }
            $cleanedHeaders = $this->patcher->cleanHeaders($rawHeaders);
            $contentType = (string) ($cleanedHeaders['Content-Type'] ?? $cleanedHeaders['content-type'] ?? 'application/json');

            if (stripos($contentType, 'text/event-stream') !== false) {
                $observer = Mirror::resolveBackendStreamObserver();
                $streamHeaders = $this->security->streamingHeaders((string) ($request->header('X-Request-Id') ?: ''));
                $streamHeaders['Cache-Control'] = 'no-cache, no-transform';

                return response()->stream(function () use ($upstream, $status, $releaseOnce, $observer, $request, $path, $ctx): void {
                    $telemetry = [
                        'status' => $status,
                        'conversation_id' => null,
                        'title' => null,
                        'model' => null,
                        'saw_done' => false,
                        'has_content' => false,
                        'image_gen_triggered' => false,
                        'image_asset_ids' => [],
                        'image_count' => 0,
                        'bytes_sent' => 0,
                    ];

                    if ($observer !== null) {
                        $observer('started', $telemetry, $request, $path, $ctx);
                    }

                    $lineBuffer = '';
                    try {
                        $body = $upstream->getBody();
                        while (!$body->eof()) {
                            $chunk = $body->read(1024);
                            if ($chunk === '') {
                                continue;
                            }

                            $telemetry['bytes_sent'] += strlen($chunk);
                            echo $chunk;
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            @flush();

                            if ($observer !== null) {
                                $lineBuffer .= $chunk;
                                if (strlen($lineBuffer) > 524288) {
                                    $lineBuffer = substr($lineBuffer, -65536);
                                }
                                while (($nl = strpos($lineBuffer, "\n")) !== false) {
                                    $line = trim(substr($lineBuffer, 0, $nl));
                                    $lineBuffer = substr($lineBuffer, $nl + 1);
                                    $this->inspectSseLine($line, $telemetry, $observer, $request, $path, $ctx);
                                }
                            }
                        }

                        if ($observer !== null && trim($lineBuffer) !== '') {
                            $this->inspectSseLine(trim($lineBuffer), $telemetry, $observer, $request, $path, $ctx);
                        }

                        $telemetry['image_count'] = $this->computeImageCount($telemetry);

                        if ($observer !== null) {
                            $eventType = ($status < 400 && ($telemetry['saw_done'] || $telemetry['has_content']))
                                ? 'completed'
                                : 'failed';
                            $observer($eventType, $telemetry, $request, $path, $ctx);
                        }
                    } finally {
                        $releaseOnce($status < 400);
                    }
                }, $status, $streamHeaders);
            }

            $bodyStr = (string) $upstream->getBody();
            $releaseOnce($status < 400);

            if (isset($cleanedHeaders['Location']) || isset($cleanedHeaders['location'])) {
                $locKey = isset($cleanedHeaders['Location']) ? 'Location' : 'location';
                $location = (string) $cleanedHeaders[$locKey];
                $upstreamHost = parse_url((string) config('mirror.upstream', 'https://chatgpt.com'), PHP_URL_HOST);
                if ($upstreamHost && str_contains($location, $upstreamHost)) {
                    $cleanedHeaders[$locKey] = str_replace(
                        ['https://' . $upstreamHost, 'http://' . $upstreamHost],
                        $request->getSchemeAndHttpHost(),
                        $location
                    );
                }
            }

            $response = response($bodyStr, $status, $cleanedHeaders);
            $responseFilter = Mirror::resolveBackendResponseFilter();
            if ($responseFilter !== null) {
                $filtered = $responseFilter($response, $request, $path, $ctx);
                if ($filtered instanceof Response) {
                    return $filtered;
                }
            }

            return $response;
        } catch (\Throwable $e) {
            $releaseOnce(false);
            $observer = Mirror::resolveBackendStreamObserver();
            if ($observer !== null && str_contains($path, 'conversation')) {
                $observer('failed', ['status' => 502, 'error' => $e->getMessage()], $request, $path, $ctx);
            }

            return response()->json([
                'error' => 'Backend Proxy Error: ' . $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Parses a single SSE line incrementally to capture conversation IDs, titles, models, and image generation events.
     *
     * @param array<string, mixed> $telemetry
     * @param callable $observer
     * @param array<string, mixed> $ctx
     */
    protected function inspectSseLine(
        string $line,
        array &$telemetry,
        callable $observer,
        Request $request,
        string $path,
        array $ctx
    ): void {
        if (!str_starts_with($line, 'data:')) {
            return;
        }

        $data = trim(substr($line, 5));
        if ($data === '') {
            return;
        }

        if ($data === '[DONE]') {
            $telemetry['saw_done'] = true;
            return;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            if (str_contains($data, 'image_gen') || str_contains($data, 'dalle.text2im') || str_contains($data, 'image_asset_pointer')) {
                $telemetry['image_gen_triggered'] = true;
                $telemetry['has_content'] = true;
            }
            return;
        }

        $prevConvId = $telemetry['conversation_id'];
        $this->walkSseNode($decoded, $telemetry);

        if ($telemetry['conversation_id'] !== null && $telemetry['conversation_id'] !== $prevConvId) {
            $observer('conversation_info', $telemetry, $request, $path, $ctx);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $telemetry
     */
    protected function walkSseNode(array $node, array &$telemetry, string $inheritedRole = ''): void
    {
        if (!empty($node['conversation_id']) && is_string($node['conversation_id'])) {
            $telemetry['conversation_id'] = $node['conversation_id'];
        }
        if (!empty($node['title']) && is_string($node['title'])) {
            $telemetry['title'] = $node['title'];
        }

        $role = $inheritedRole;
        if (isset($node['author']['role']) && is_string($node['author']['role'])) {
            $role = strtolower($node['author']['role']);
        } elseif (isset($node['message']['author']['role']) && is_string($node['message']['author']['role'])) {
            $role = strtolower($node['message']['author']['role']);
        }

        $isUserTurn = $role === 'user';
        $authorName = strtolower((string) ($node['author']['name'] ?? ''));
        $recipient = strtolower((string) ($node['recipient'] ?? ''));
        $contentType = strtolower((string) ($node['content']['content_type'] ?? $node['content_type'] ?? ''));

        if (
            !$isUserTurn
            && (
                in_array($recipient, ['image_gen', 'dalle.text2im', 'dalle', 't2i', 'image_generation'], true)
                || str_contains($recipient, 'image_gen')
                || str_contains($authorName, 'dalle')
                || str_contains($authorName, 'image_gen')
                || $contentType === 'image_asset_pointer'
            )
        ) {
            $telemetry['image_gen_triggered'] = true;
            $telemetry['has_content'] = true;
        }

        if (
            !$isUserTurn
            && !empty($node['asset_pointer'])
            && is_string($node['asset_pointer'])
            && (str_starts_with($node['asset_pointer'], 'file-service://') || str_starts_with($node['asset_pointer'], 'sediment://'))
        ) {
            if ($contentType === 'image_asset_pointer' || $telemetry['image_gen_triggered'] || isset($node['width'])) {
                $telemetry['image_gen_triggered'] = true;
                $telemetry['image_asset_ids'][$node['asset_pointer']] = true;
                $telemetry['has_content'] = true;
            }
        }

        if (!empty($node['metadata']['model_slug']) && is_string($node['metadata']['model_slug'])) {
            $telemetry['model'] = $node['metadata']['model_slug'];
        } elseif (!empty($node['model_slug']) && is_string($node['model_slug'])) {
            $telemetry['model'] = $node['model_slug'];
        }

        if (!$isUserTurn && ($contentType === 'text' || $contentType === 'multimodal_text' || $contentType === 'thoughts' || isset($node['parts']))) {
            $telemetry['has_content'] = true;
        }
        if (isset($node['p'], $node['o']) && is_string($node['p']) && str_contains($node['p'], '/message')) {
            $telemetry['has_content'] = true;
        }
        if (!$isUserTurn && isset($node['v']) && is_string($node['v']) && $node['v'] !== '') {
            $telemetry['has_content'] = true;
            if (str_contains($node['v'], 'image_gen') || str_contains($node['v'], 'image_asset_pointer') || str_contains($node['v'], 'sediment://')) {
                $telemetry['image_gen_triggered'] = true;
            }
        }

        foreach ($node as $key => $val) {
            if (is_array($val)) {
                $nextRole = ($key === 'input_message') ? 'user' : $role;
                $this->walkSseNode($val, $telemetry, $nextRole);
            }
        }
    }

    /**
     * @param array<string, mixed> $telemetry
     */
    protected function computeImageCount(array $telemetry): int
    {
        $assetCount = is_array($telemetry['image_asset_ids'] ?? null)
            ? count($telemetry['image_asset_ids'])
            : 0;

        if ($assetCount > 0) {
            return $assetCount;
        }

        return !empty($telemetry['image_gen_triggered']) ? 1 : 0;
    }
}
