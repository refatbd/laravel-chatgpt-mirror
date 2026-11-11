<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepares universal reverse-proxy, FastCGI/Apache/Nginx/LiteSpeed/Caddy/Octane requests:
 *  - Applies live DB runtime overrides (Redis, Proxy, HTTPS, SSE, Pool, CORS, Rate Limiting)
 *  - Restores Authorization header from REDIRECT_HTTP_AUTHORIZATION (Apache mod_rewrite/FastCGI quirk)
 *  - Temporarily trusts configured reverse-proxy X-Forwarded-* headers per request without leaking static state
 *  - Applies dynamic CORS headers and handles API OPTIONS preflight cleanly
 *  - Enforces optional dynamic fixed-window rate limiting via MirrorCache when enabled
 *  - Disables zlib output compression cleanly outside unit tests
 */
class PrepareMirrorRuntime
{
    public function __construct(
        protected ?DynamicConfigService $dynamicConfig = null,
        protected ?SecurityService $security = null,
        protected ?MirrorCache $cache = null
    ) {
        $this->dynamicConfig ??= (function_exists('app') && app()->bound(DynamicConfigService::class))
            ? app(DynamicConfigService::class)
            : new DynamicConfigService();
        $this->security ??= (function_exists('app') && app()->bound(SecurityService::class))
            ? app(SecurityService::class)
            : new SecurityService();
        $this->cache ??= (function_exists('app') && app()->bound(MirrorCache::class))
            ? app(MirrorCache::class)
            : new MirrorCache();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->dynamicConfig?->ensureApplied();
        $this->normalizeApacheAuthorizationHeader($request);

        if (!app()->runningUnitTests() && config('mirror.server.disable_output_compression', true)) {
            @ini_set('zlib.output_compression', '0');
        }

        if (!config('mirror.server.trust_proxies', true)) {
            return $this->processGatewayLifecycle($request, $next);
        }

        $prevProxies = SymfonyRequest::getTrustedProxies();
        $prevHeaders = SymfonyRequest::getTrustedHeaderSet();

        $trusted = $this->resolveTrustedProxyList($request);
        if ($trusted !== []) {
            $headerMask =
                SymfonyRequest::HEADER_X_FORWARDED_FOR |
                SymfonyRequest::HEADER_X_FORWARDED_HOST |
                SymfonyRequest::HEADER_X_FORWARDED_PORT |
                SymfonyRequest::HEADER_X_FORWARDED_PROTO;
            if (defined(SymfonyRequest::class . '::HEADER_X_FORWARDED_PREFIX')) {
                $headerMask |= SymfonyRequest::HEADER_X_FORWARDED_PREFIX;
            }
            SymfonyRequest::setTrustedProxies($trusted, $headerMask);
        }

        try {
            return $this->processGatewayLifecycle($request, $next);
        } finally {
            SymfonyRequest::setTrustedProxies($prevProxies, $prevHeaders);
        }
    }

    protected function processGatewayLifecycle(Request $request, Closure $next): Response
    {
        $isApiCorsTarget = $this->isApiCorsTarget($request);

        if ($isApiCorsTarget && $request->isMethod('OPTIONS')) {
            return $this->applyDynamicCorsHeaders(response('', 204), $request, includePreflight: true);
        }

        $rateLimitHeaders = [];
        if ($this->shouldRateLimit($request)) {
            $limitState = $this->consumeRateLimit($request);
            $rateLimitHeaders = $limitState['headers'];

            if ($limitState['exceeded']) {
                $requestId = trim((string) ($request->header('X-Request-Id') ?: ''));
                $payload = ($this->security ?? new SecurityService())->apiErrorEnvelope(
                    429,
                    "Rate limit exceeded ({$limitState['max']} requests per {$limitState['decay']}s). Retry in {$limitState['retry_after']}s.",
                    $requestId !== '' ? $requestId : null,
                    'rate_limit_exceeded'
                );
                $response = response()->json($payload, 429);
                foreach ($rateLimitHeaders as $k => $v) {
                    $response->headers->set($k, $v);
                }
                return $isApiCorsTarget ? $this->applyDynamicCorsHeaders($response, $request) : $response;
            }
        }

        $response = $next($request);

        foreach ($rateLimitHeaders as $k => $v) {
            if (!$response->headers->has($k)) {
                $response->headers->set($k, $v);
            }
        }

        if ($isApiCorsTarget) {
            $response = $this->applyDynamicCorsHeaders($response, $request);
        }

        return $response;
    }

    protected function isApiCorsTarget(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        $openaiPrefix = trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');
        $docsPrefix = trim((string) config('mirror.routes.docs_prefix', 'mirror/api-docs'), '/');
        $docsBase = explode('/', $docsPrefix)[0] ?? 'mirror';

        return ($openaiPrefix !== '' && ($path === $openaiPrefix || str_starts_with($path, $openaiPrefix . '/')))
            || $path === 'api'
            || str_starts_with($path, 'api/')
            || str_starts_with($path, 'backend-api/')
            || str_starts_with($path, '__codex-api/')
            || $path === $docsBase . '/openapi';
    }

    protected function applyDynamicCorsHeaders(Response $response, Request $request, bool $includePreflight = false): Response
    {
        if (!filter_var(config('mirror.cors.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return $response;
        }

        $configured = trim((string) config('mirror.cors.allowed_origins', '*'));
        $requestOrigin = trim((string) $request->header('Origin', ''));
        $matchedOrigin = null;

        if ($configured === '' || $configured === '*') {
            $matchedOrigin = '*';
        } else {
            $candidates = array_values(array_filter(array_map('trim', explode(',', $configured))));
            $normalizedRequestOrigin = rtrim(strtolower($requestOrigin), '/');
            foreach ($candidates as $candidate) {
                if ($candidate === '*') {
                    $matchedOrigin = '*';
                    break;
                }
                if ($normalizedRequestOrigin !== '' && rtrim(strtolower($candidate), '/') === $normalizedRequestOrigin) {
                    $matchedOrigin = $requestOrigin;
                    break;
                }
            }
            $existingVary = trim((string) $response->headers->get('Vary', ''));
            if ($existingVary === '') {
                $response->headers->set('Vary', 'Origin');
            } elseif (!str_contains(strtolower($existingVary), 'origin')) {
                $response->headers->set('Vary', $existingVary . ', Origin');
            }
        }

        if ($matchedOrigin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $matchedOrigin);
            $expose = trim((string) config('mirror.cors.expose_headers', 'X-Request-Id, X-Mirror-Conversation-Id, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After'));
            if ($expose !== '' && !$response->headers->has('Access-Control-Expose-Headers')) {
                $response->headers->set('Access-Control-Expose-Headers', $expose);
            }

            if ($includePreflight || $request->isMethod('OPTIONS')) {
                $methods = trim((string) config('mirror.cors.allow_methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS'));
                $headers = trim((string) config('mirror.cors.allow_headers', 'Authorization, Content-Type, X-Requested-With, X-Request-Id, Accept, Origin'));
                $response->headers->set('Access-Control-Allow-Methods', $methods !== '' ? $methods : 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', $headers !== '' ? $headers : 'Authorization, Content-Type, Accept, Origin');
                $response->headers->set('Access-Control-Max-Age', '86400');
            }
        }

        return $response;
    }

    protected function shouldRateLimit(Request $request): bool
    {
        if (!filter_var(config('mirror.rate_limit.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        if ($request->isMethod('OPTIONS') || $request->isMethod('HEAD')) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        $openaiPrefix = trim((string) config('mirror.routes.openai_prefix', 'v1'), '/');

        if ($openaiPrefix !== '' && ($path === $openaiPrefix || str_starts_with($path, $openaiPrefix . '/'))) {
            return $path !== $openaiPrefix . '/capabilities';
        }

        return $path === 'api/chat'
            || str_starts_with($path, 'api/chat/')
            || str_starts_with($path, 'api/conversations')
            || str_starts_with($path, 'api/files')
            || str_starts_with($path, 'api/convert')
            || str_starts_with($path, 'api/decoder-challenges');
    }

    /**
     * @return array{exceeded: bool, max: int, decay: int, remaining: int, retry_after: int, headers: array<string, string>}
     */
    protected function consumeRateLimit(Request $request): array
    {
        $max = max(1, (int) config('mirror.rate_limit.max_attempts', 180));
        $decay = max(1, (int) config('mirror.rate_limit.decay_seconds', 60));

        $bearer = ($this->security ?? new SecurityService())->bearerToken($request);
        $subject = is_string($bearer) && $bearer !== ''
            ? 'key_' . substr(hash('sha256', $bearer), 0, 16)
            : 'ip_' . substr(hash('sha256', (string) ($request->ip() ?: 'unknown')), 0, 16);

        $now = time();
        $windowIndex = (int) floor($now / $decay);
        $retryAfter = max(1, (($windowIndex + 1) * $decay) - $now);
        $suffix = "ratelimit:{$subject}:{$decay}:{$windowIndex}";

        $cache = $this->cache ?? new MirrorCache();
        $fullKey = $cache->key($suffix);
        $count = null;

        try {
            $store = $cache->store();
            $store->add($fullKey, 0, $decay + 5);
            $val = $store->increment($fullKey, 1);
            if (is_int($val)) {
                $count = $val;
            }
        } catch (\Throwable) {
        }

        if ($count === null) {
            $count = $cache->increment($suffix, 1, $decay + 5);
        }

        $exceeded = $count > $max;
        $remaining = max(0, $max - $count);

        $headers = [
            'X-RateLimit-Limit' => (string) $max,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $retryAfter,
        ];
        if ($exceeded) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return [
            'exceeded' => $exceeded,
            'max' => $max,
            'decay' => $decay,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
            'headers' => $headers,
        ];
    }

    protected function normalizeApacheAuthorizationHeader(Request $request): void
    {
        if ($request->headers->has('Authorization')) {
            return;
        }

        $candidate = $request->server('HTTP_AUTHORIZATION')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION');

        if (is_string($candidate) && trim($candidate) !== '') {
            $val = trim($candidate);
            $request->headers->set('Authorization', $val);
            $request->server->set('HTTP_AUTHORIZATION', $val);
        }
    }

    /**
     * @return string[]
     */
    protected function resolveTrustedProxyList(Request $request): array
    {
        $raw = (string) config('mirror.server.trusted_proxies', '*');
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        if ($trimmed === '*') {
            $remote = trim((string) $request->server('REMOTE_ADDR', ''));
            $list = ['127.0.0.1', '::1'];
            if ($remote !== '' && !in_array($remote, $list, true)) {
                $list[] = $remote;
            }
            return $list;
        }

        return array_values(array_filter(array_map('trim', explode(',', $trimmed))));
    }
}
