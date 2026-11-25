<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response as UpstreamResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Refatbd\LaravelChatGptMirror\Controllers\Concerns\ResolvesWebProxyContext;
use Refatbd\LaravelChatGptMirror\Mirror;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies the ChatGPT web UI and static bundles through Mirror cleanly:
 * strips blocking security headers, rewrites upstream origins, sanitizes SSR tokens,
 * supports SaaS-scoped token/remote contexts & Shadow-DOM widget injection cleanly.
 */
class BrowserProxyController extends Controller
{
    use ResolvesWebProxyContext;

    protected Client $http;
    protected BrowserPatchManager $patcher;
    protected UrlRewriter $rewriter;
    protected TokenPoolManager $pool;
    protected SecurityService $security;
    protected MirrorCache $cache;

    public function __construct(
        BrowserPatchManager $patcher,
        UrlRewriter $rewriter,
        TokenPoolManager $pool,
        SecurityService $security,
        ?MirrorCache $cache = null,
        ?Client $http = null
    ) {
        $this->patcher = $patcher;
        $this->rewriter = $rewriter;
        $this->pool = $pool;
        $this->security = $security;
        $this->cache = $cache ?? (app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache());

        $this->http = $http ?? new Client([
            'base_uri' => rtrim((string) config('mirror.upstream', 'https://chatgpt.com'), '/'),
            'timeout' => (float) config('mirror.http.timeout', 60),
            'connect_timeout' => (float) config('mirror.http.connect_timeout', 15),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function proxy(Request $request, string $path = ''): Response
    {
        $guard = Mirror::resolveProxyRequestGuard();
        if ($guard !== null && $guard($request) === false) {
            abort(404);
        }

        if (str_starts_with($request->path(), 'ces/noop')) {
            return response('', 204);
        }

        $targetPath = '/' . ltrim($path, '/');
        if ($targetPath === '/' && $request->path() !== '/') {
            $targetPath = '/' . ltrim($request->path(), '/');
        } else {
            foreach (['images', 'assets', 'fonts', '_next'] as $pfx) {
                if ($request->is($pfx . '*') && !str_starts_with($targetPath, '/' . $pfx . '/')) {
                    $targetPath = '/' . $pfx . $targetPath;
                    break;
                }
            }
        }

        $isStaticAsset = preg_match('#\.(js|mjs|css|woff2?|ttf|otf|png|jpg|jpeg|gif|svg|ico|json|webp)$#i', $targetPath) === 1;
        $request->attributes->set('mirror_is_static_asset', $isStaticAsset);

        $rawRequestPath = '/' . ltrim($request->path(), '/');
        if (str_starts_with($rawRequestPath, '/cdn/v2/assets/')) {
            $targetPath = '/cdn' . substr($rawRequestPath, strlen('/cdn/v2'));
        }
        $isCdnRoute = str_starts_with($targetPath, '/cdn/') || str_starts_with($rawRequestPath, '/cdn/');
        $isStaticGetOrHead = ($request->isMethod('get') || $request->isMethod('head')) && $isStaticAsset && !str_starts_with($targetPath, '/api/');
        $localOrigin = $this->security->resolveOrigin($request);
        $query = (string) ($request->server->get('QUERY_STRING') ?: (explode('?', (string) $request->server->get('REQUEST_URI', ''), 2)[1] ?? $request->getQueryString()));
        $assetsInRedis = (bool) config('mirror.cache.assets_in_redis', true) && $this->isRedisCacheDriver();

        // The browser adds a retry nonce to force a fresh fetch without changing the CDN module.
        if ($isCdnRoute && $isStaticGetOrHead && preg_match('#^/cdn/(?:v2/)?assets/[\w.-]+\.[a-f0-9]{8,64}\.m?js$#iD', $rawRequestPath) === 1) {
            $query = implode('&', array_filter(explode('&', $query), static fn (string $param): bool => !str_starts_with($param, '__codex_import_retry=')));
        }

        // Fast-path for cached public /cdn/* static assets BEFORE auth/token-pool/session DB queries.
        if ($isCdnRoute && $isStaticGetOrHead && !str_starts_with($rawRequestPath, '/cdn/v2/')) {
            $fastTargetPath = str_starts_with($targetPath, '/cdn/') ? substr($targetPath, 4) : $targetPath;
            $fastFullPath = $fastTargetPath . ($query ? '?' . $query : '');
            $fastCacheKey = md5($localOrigin . '||' . $fastFullPath);
            $fastCacheDir = storage_path('framework/mirror-assets');
            $fastCacheFile = $fastCacheDir . '/' . $fastCacheKey;
            $fastMetaFile = $fastCacheDir . '/' . $fastCacheKey . '.meta.json';

            if (is_file($fastCacheFile) && is_file($fastMetaFile)) {
                $meta = json_decode((string) file_get_contents($fastMetaFile), true) ?: [];
                $ct = (string) ($meta['content_type'] ?? $this->guessMimeType($fastTargetPath));
                return response($request->isMethod('head') ? '' : (string) file_get_contents($fastCacheFile), 200, [
                    'Content-Type' => $ct,
                    'Cache-Control' => $this->staticCacheControl($fastTargetPath, $ct, true),
                    'Alt-Svc' => 'clear',
                ]);
            }
        }

        $ctx = $this->resolveWebProxyContext($request);
        if ($ctx['short_circuit'] instanceof Response) {
            return $ctx['short_circuit'];
        }

        $token = $ctx['token'];
        $remoteBase = $ctx['upstream_url'];
        $basePath = (string) ($ctx['base_path'] ?? '');

        try {
            if ($isCdnRoute) {
                $relayCdn = $remoteBase !== null && !empty($ctx['relay_cdn']);
                $baseOverride = $relayCdn
                    ? $remoteBase
                    : 'https://cdn.oaistatic.com';
                if (!$relayCdn && str_starts_with($targetPath, '/cdn/')) {
                    $targetPath = substr($targetPath, 4);
                } elseif ($relayCdn && !str_starts_with($targetPath, '/cdn/')) {
                    $targetPath = '/cdn' . $targetPath;
                }
            } else {
                $baseOverride = $remoteBase;
            }

            $fullPath = $targetPath . ($query ? '?' . $query : '');

            $assetsTtl = max(60, (int) config('mirror.cache.assets_ttl', 86400));
            $assetsMaxBytes = max(1024, (int) config('mirror.cache.assets_redis_max_bytes', 262144));

            if ($isStaticGetOrHead) {
                $assetVariant = config('mirror.browser.proxy_cdn_assets', false) ? 'proxy' : 'direct';
                $cacheKey = md5('v2|' . $assetVariant . '|' . $localOrigin . '|' . $basePath . '|' . ($baseOverride ?: '') . $fullPath);
                $redisKey = 'asset:' . $cacheKey;

                $cacheDir = storage_path('framework/mirror-assets');
                $cacheFile = $cacheDir . '/' . $cacheKey;
                $metaFile = $cacheDir . '/' . $cacheKey . '.meta.json';

                if (File::exists($cacheFile) && File::exists($metaFile)) {
                    $meta = json_decode((string) File::get($metaFile), true) ?: [];
                    $ct = (string) ($meta['content_type'] ?? $this->guessMimeType($targetPath));
                    return response($request->isMethod('head') ? '' : File::get($cacheFile), 200, [
                        'Content-Type' => $ct,
                        'Cache-Control' => $this->staticCacheControl($targetPath, $ct, $isCdnRoute),
                        'Alt-Svc' => 'clear',
                    ]);
                }

                if ($assetsInRedis) {
                    $cached = $this->cache->get($redisKey);
                    if (is_array($cached) && isset($cached['body_b64']) && is_string($cached['body_b64'])) {
                        $decoded = base64_decode($cached['body_b64'], true);
                        if (is_string($decoded)) {
                            $ct = (string) ($cached['content_type'] ?? $this->guessMimeType($targetPath));
                            return response($request->isMethod('head') ? '' : $decoded, 200, [
                                'Content-Type' => $ct,
                                'Cache-Control' => $this->staticCacheControl($targetPath, $ct, $isCdnRoute),
                                'Alt-Svc' => 'clear',
                            ]);
                        }
                    }
                }
            }

            if (!$token && empty($ctx['skip_token_pool']) && !empty($ctx['meta']['fail_on_missing_token'])) {
                if (($ctx['meta']['pool_status'] ?? null) === 'busy') {
                    return $this->workspaceBusyResponse($request, $ctx);
                }
                return response()->json([
                    'error' => 'No active workspace session token is currently available on this server.',
                ], 503);
            }

            $upstreamTarget = $baseOverride ?: (string) config('mirror.upstream', 'https://chatgpt.com');
            $upstreamHost = parse_url($upstreamTarget, PHP_URL_HOST) ?: 'chatgpt.com';

            $clientReferer = $request->header('Referer');
            $deepReferer = 'https://' . $upstreamHost . '/';
            if ($clientReferer && is_string($clientReferer)) {
                $refParts = parse_url($clientReferer);
                $refPath = $refParts['path'] ?? '/';
                if (!str_starts_with($refPath, '/_unigpt/')) {
                    $deepReferer = 'https://' . $upstreamHost . $refPath . (!empty($refParts['query']) ? '?' . $refParts['query'] : '');
                }
            }

            $headers = [
                'User-Agent' => (string) config('mirror.http.user_agent', $request->userAgent() ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
                'Accept' => (string) $request->header('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
                'Accept-Language' => 'en-US,en;q=0.9',
                'Origin' => 'https://' . $upstreamHost,
                'Referer' => $deepReferer,
            ];

            // Forward browser client-hints matching defcron-mirror
            $forwardPrefixes = ['chatgpt-', 'oai-', 'openai-', 'x-conduit-', 'x-oai-', 'x-openai-'];
            $forwardExact = [
                'baggage', 'cache-control', 'pragma', 'priority', 'range', 'sentry-trace',
                'dnt', 'sec-ch-ua', 'sec-ch-ua-mobile', 'sec-ch-ua-platform',
                'sec-fetch-dest', 'sec-fetch-mode', 'sec-fetch-site', 'sec-fetch-user',
            ];
            foreach ($request->headers->all() as $rawHeaderName => $headerVals) {
                $lowerName = strtolower($rawHeaderName);
                $matchesPrefix = false;
                foreach ($forwardPrefixes as $pfx) {
                    if (str_starts_with($lowerName, $pfx)) {
                        $matchesPrefix = true;
                        break;
                    }
                }
                if ($matchesPrefix || in_array($lowerName, $forwardExact, true)) {
                    $headers[$rawHeaderName] = is_array($headerVals) ? implode(', ', $headerVals) : (string) $headerVals;
                }
            }

            if ($remoteBase === null && !($isCdnRoute && $isStaticGetOrHead)) {
                $cookies = [];
                if ($token && $token->session_token) {
                    $cookies[] = '__Secure-next-auth.session-token=' . $token->session_token;
                }
                $clientCookie = $request->header('Cookie');
                if ($clientCookie) {
                    $localNames = ['mirror_control', 'unigpt_proxy_session', 'unigpt_device_uuid', 'xsrf-token', strtolower((string) config('session.cookie'))];
                    foreach (explode(';', $clientCookie) as $cookie) {
                        $cookie = trim($cookie);
                        $name = strtolower(trim(explode('=', $cookie, 2)[0]));
                        if (!in_array($name, $localNames, true)
                            && !str_starts_with($name, 'remember_')
                            && !preg_match('/^(?:__secure-|__host-)?next-auth\.session-token(?:\.\d+)?$/D', $name)) {
                            $cookies[] = $cookie;
                        }
                    }
                }
                if ($cookies !== []) {
                    $headers['Cookie'] = implode('; ', $cookies);
                }
            }

            foreach ($ctx['upstream_headers'] as $hk => $hv) {
                $headers[$hk] = $hv;
            }

            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
                $headers['Content-Type'] = (string) $request->header('Content-Type', 'application/json');
            }

            $options = [
                'headers' => $headers,
                'verify' => (bool) config('mirror.http.verify_ssl', true),
                'timeout' => (float) config('mirror.http.timeout', 60),
                'connect_timeout' => (float) config('mirror.http.connect_timeout', 15),
            ];
            $proxyCfg = $ctx['http_proxy'] ?? config('mirror.http.proxy');
            if (is_string($proxyCfg) && trim($proxyCfg) !== '') {
                $options['proxy'] = trim($proxyCfg);
            }
            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
                $options['body'] = $request->getContent();
            }

            $upstreamUrl = $baseOverride ? (rtrim($baseOverride, '/') . $fullPath) : $fullPath;
            // Cache only anonymous, fingerprinted assets from the official CDN before origin rewriting.
            $rawAssetFile = $request->isMethod('get') && $isCdnRoute && $remoteBase === null
                && $baseOverride === 'https://cdn.oaistatic.com' && $query === ''
                && $options['verify'] && empty($ctx['upstream_headers']) && !$request->headers->has('Range')
                && preg_match('#^/assets/(?:async/)?[\w.-]*[a-f0-9]{8,64}\.(?:m?js|css)$#iD', $targetPath) === 1
                ? storage_path('framework/mirror-assets/raw-v2-' . hash('sha256', $targetPath))
                : null;
            $response = $rawAssetFile === null ? null : $this->readPublicCdnAsset($rawAssetFile);
            $miss = $response === null;
            if ($rawAssetFile !== null) {
                $options['headers'] = [
                    'User-Agent' => $headers['User-Agent'],
                    'Accept' => '*/*',
                    'Origin' => 'https://chatgpt.com',
                    'Referer' => 'https://chatgpt.com/',
                ];
            }
            $response ??= $this->http->request($request->method(), $upstreamUrl, $options);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            if ($miss && $rawAssetFile !== null && $status === 200) {
                $type = $response->getHeaderLine('Content-Type');
                $cacheControl = $response->getHeaderLine('Cache-Control');
                if (preg_match('#^(?:application/(?:x-)?(?:java|ecma)script|text/(?:javascript|css))(?:\s*;|$)#i', $type)
                    && preg_match('/\bpublic\b/i', $cacheControl)
                    && !preg_match('/\b(?:private|no-store|no-cache)\b/i', $cacheControl)
                    && preg_match('/(?:^|,\s*)max-age\s*=\s*([1-9][0-9]*)\b/i', $cacheControl, $age) === 1
                    && !preg_match('/(?:^|,\s*)s-maxage\s*=\s*0(?:\s*,|\s*$)/i', $cacheControl)
                    && preg_match('/^(?:(?:accept-encoding|origin)(?:\s*,\s*|$))*$/iD', $response->getHeaderLine('Vary')) === 1
                    && !str_contains($type, "\n") && $body !== '' && strlen($body) <= 8 * 1024 * 1024) {
                    try {
                        File::ensureDirectoryExists(dirname($rawAssetFile));
                        $metadata = json_encode([
                            'type' => $type,
                            'expires' => time() + min($assetsTtl, (int) $age[1]),
                        ], JSON_THROW_ON_ERROR);
                        File::replace($rawAssetFile, $metadata . "\n" . $body);
                    } catch (\Throwable) {
                        // Asset delivery can continue when the local cache is unavailable.
                    }
                }
            }

            $rawHeaders = [];
            foreach ($response->getHeaders() as $k => $vals) {
                $rawHeaders[$k] = $vals[0] ?? '';
            }

            if (isset($rawHeaders['Location']) || isset($rawHeaders['location'])) {
                $loc = $rawHeaders['Location'] ?? $rawHeaders['location'];
                $rawHeaders['Location'] = $this->rewriter->rewriteText($loc, $localOrigin, 'text/plain', $remoteBase, $basePath);
                unset($rawHeaders['location']);
            }

            $cleanedHeaders = $this->patcher->cleanHeaders($rawHeaders);
            $cleanedHeaders['Alt-Svc'] = 'clear';
            $headerFilter = Mirror::resolveBrowserResponseHeadersFilter();
            if ($headerFilter !== null) {
                $filteredHeaders = $headerFilter($cleanedHeaders, $request, $ctx);
                if (is_array($filteredHeaders)) {
                    $cleanedHeaders = $filteredHeaders;
                }
            }

            $contentType = (string) ($cleanedHeaders['Content-Type'] ?? $cleanedHeaders['content-type'] ?? $this->guessMimeType($targetPath));

            if ($isCdnRoute && $isStaticGetOrHead && $status !== 200 && $status !== 304) {
                $cleanedHeaders['Cache-Control'] = 'no-store';
                return response($body, $status, $cleanedHeaders);
            }

            if (stripos($contentType, 'text/html') !== false) {
                $body = $this->patcher->patchHtml($body, $localOrigin, $basePath, $remoteBase);

                $htmlInjector = Mirror::resolveBrowserHtmlInjector();
                if ($htmlInjector !== null) {
                    $injected = $htmlInjector($body, $request, $ctx);
                    if (is_string($injected) && $injected !== '') {
                        $body = $injected;
                    }
                }

                $cleanedHeaders['Cache-Control'] = 'no-store';
                $resp = response($body, $status, $cleanedHeaders);
                return empty($ctx['meta']['skip_control_cookie'])
                    ? $this->security->attachControlCookie($resp, $request)
                    : $resp;
            }

            if (
                stripos($contentType, 'javascript') !== false ||
                stripos($contentType, 'text/css') !== false ||
                stripos($contentType, 'application/json') !== false
            ) {
                $body = $this->rewriter->rewriteText($body, $localOrigin, $contentType, $remoteBase, $basePath);
                if ($isCdnRoute && $isStaticGetOrHead && $status === 200) {
                    $cleanedHeaders['Cache-Control'] = $this->staticCacheControl($targetPath, $contentType, true);
                    unset($cleanedHeaders['Set-Cookie'], $cleanedHeaders['set-cookie'], $cleanedHeaders['Pragma'], $cleanedHeaders['pragma'], $cleanedHeaders['Expires'], $cleanedHeaders['expires']);
                } else {
                    $cleanedHeaders['Cache-Control'] = 'no-store';
                }
            } elseif ($isCdnRoute && $isStaticGetOrHead && $status === 200) {
                $cleanedHeaders['Cache-Control'] = $this->staticCacheControl($targetPath, $contentType, true);
                unset($cleanedHeaders['Set-Cookie'], $cleanedHeaders['set-cookie'], $cleanedHeaders['Pragma'], $cleanedHeaders['pragma'], $cleanedHeaders['Expires'], $cleanedHeaders['expires']);
            }

            if ($status === 200 && $request->isMethod('get') && $isStaticAsset && isset($cacheKey, $cacheFile, $metaFile)) {
                if ($assetsInRedis && strlen($body) <= $assetsMaxBytes) {
                    $this->cache->put('asset:' . $cacheKey, [
                        'body_b64' => base64_encode($body),
                        'content_type' => $contentType,
                    ], $assetsTtl);
                }

                try {
                    File::ensureDirectoryExists(dirname($cacheFile));
                    File::replace($cacheFile, $body);
                    File::replace($metaFile, json_encode(['content_type' => $contentType]));
                } catch (\Throwable) {
                }
            }

            return response($body, $status, $cleanedHeaders);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Mirror Browser Proxy Error: ' . $e->getMessage(),
            ], 502);
        } finally {
            $this->releaseWebProxyContext($ctx, true);
        }
    }

    private function workspaceBusyResponse(Request $request, array $ctx): Response
    {
        $headers = ['Retry-After' => '1', 'X-UniGPT-Pool-Status' => 'busy', 'Cache-Control' => 'private, no-store'];
        if (!$request->isMethod('get') || $request->expectsJson() || !str_contains((string) $request->header('Accept'), 'text/html')) {
            return response()->json([
                'error' => 'This workspace is busy. Please try again in a moment.',
                'code' => 'unigpt_pool_busy',
            ], 503, $headers);
        }

        $wait = max(0, min(60, (int) ($ctx['meta']['pool_busy_wait_seconds'] ?? 0)));
        $page = <<<HTML
        <!doctype html><html lang="en"><meta charset="utf-8"><title>Workspace busy</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>body{font:1rem system-ui;margin:10vh auto;padding:0 1rem;max-width:32rem}button{font:inherit;padding:.4rem .7rem}</style>
        <body data-wait="{$wait}">
        <h1>Workspace busy</h1><p role="status">The workspace is handling other requests.</p>
        <button type="button" onclick="location.reload()">Try again</button>
        <script>
        try {
            const key = 'mirror.workspace-busy';
            const path = location.pathname + location.search;
            const now = Date.now();
            const old = JSON.parse(sessionStorage.getItem(key) || 'null');
            const run = old?.path === path && now - old.started < 120000 ? old : {path, started: now, count: 0};
            const delay = Math.min(1000 + run.count * 500, 4000);
            if (now - run.started + delay <= Number(document.body.dataset.wait) * 1000) {
                run.count++;
                sessionStorage.setItem(key, JSON.stringify(run));
                document.querySelector('[role=status]').textContent = 'Retrying this page…';
                setTimeout(() => location.reload(), delay);
            }
        } catch {}
        </script></body></html>
        HTML;

        return response($page, 503, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    protected function staticCacheControl(string $targetPath, string $contentType, bool $isCdnRoute): string
    {
        if (preg_match('#\.(?:m?js|css|json)$#iD', $targetPath) === 1
            || preg_match('#javascript|ecmascript|text/css|application/(?:[a-z0-9.+-]+\+)?json#i', $contentType) === 1
        ) {
            return 'no-store';
        }

        if ($isCdnRoute) {
            return 'public, max-age=604800, immutable';
        }

        return 'public, max-age=86400';
    }

    private function readPublicCdnAsset(string $path): ?UpstreamResponse
    {
        $cached = @file_get_contents($path);
        if (!is_string($cached) || !str_contains($cached, "\n")) {
            return null;
        }

        [$header, $body] = explode("\n", $cached, 2);
        $metadata = json_decode($header, true);
        if (!is_string($metadata['type'] ?? null) || ($metadata['expires'] ?? 0) <= time()) {
            return null;
        }

        return new UpstreamResponse(200, ['Content-Type' => $metadata['type']], $body);
    }

    protected function isRedisCacheDriver(): bool
    {
        $storeName = (string) (config('mirror.cache.store') ?: config('cache.default', 'file'));
        $driver = (string) config("cache.stores.{$storeName}.driver", $storeName);

        return $driver === 'redis';
    }

    protected function guessMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ico' => 'image/x-icon',
            default => 'text/html; charset=utf-8',
        };
    }
}
