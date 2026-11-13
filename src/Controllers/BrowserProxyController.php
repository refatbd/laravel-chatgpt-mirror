<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use GuzzleHttp\Client;
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
        $isCdnRoute = str_starts_with($targetPath, '/cdn/') || str_starts_with($rawRequestPath, '/cdn/');
        $isStaticGetOrHead = ($request->isMethod('get') || $request->isMethod('head')) && $isStaticAsset && !str_starts_with($targetPath, '/api/');
        $localOrigin = $this->security->resolveOrigin($request);
        $query = $request->getQueryString();
        $assetsInRedis = (bool) config('mirror.cache.assets_in_redis', true) && $this->isRedisCacheDriver();

        // Fast-path for cached public /cdn/* static assets BEFORE auth/token-pool/session DB queries.
        if ($isCdnRoute && $isStaticGetOrHead) {
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
            if (str_starts_with($targetPath, '/cdn/')) {
                $baseOverride = ($remoteBase !== null && !empty($ctx['relay_cdn']))
                    ? $remoteBase
                    : 'https://cdn.oaistatic.com';
                if (!(!empty($ctx['relay_cdn']) && $remoteBase !== null)) {
                    $targetPath = substr($targetPath, 4);
                }
            } else {
                $baseOverride = $remoteBase;
            }

            $fullPath = $targetPath . ($query ? '?' . $query : '');

            $assetsTtl = max(60, (int) config('mirror.cache.assets_ttl', 86400));
            $assetsMaxBytes = max(1024, (int) config('mirror.cache.assets_redis_max_bytes', 262144));

            if ($isStaticGetOrHead) {
                $cacheKey = md5($localOrigin . '|' . $basePath . '|' . ($baseOverride ?: '') . $fullPath);
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
                return response()->json([
                    'error' => 'No active workspace session token is currently available on this server.',
                ], 503);
            }

            $upstreamTarget = $baseOverride ?: (string) config('mirror.upstream', 'https://chatgpt.com');
            $upstreamHost = parse_url($upstreamTarget, PHP_URL_HOST) ?: 'chatgpt.com';

            $headers = [
                'User-Agent' => (string) config('mirror.http.user_agent', $request->userAgent() ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
                'Accept' => (string) $request->header('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
                'Accept-Language' => 'en-US,en;q=0.9',
                'Origin' => 'https://' . $upstreamHost,
                'Referer' => 'https://' . $upstreamHost . '/',
            ];

            if ($remoteBase === null && !($isCdnRoute && $isStaticGetOrHead)) {
                $cookies = [];
                if ($token && $token->session_token) {
                    $cookies[] = '__Secure-next-auth.session-token=' . $token->session_token;
                }
                $clientCookie = $request->header('Cookie');
                if ($clientCookie) {
                    $filtered = preg_replace('/(?:^|;\s*)mirror_control=[^;]*/i', '', $clientCookie);
                    $filtered = trim((string) $filtered, "; \t");
                    if ($filtered !== '') {
                        $cookies[] = $filtered;
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
            $response = $this->http->request($request->method(), $upstreamUrl, $options);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

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
                if ($isCdnRoute && $isStaticGetOrHead) {
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
                    File::put($cacheFile, $body);
                    File::put($metaFile, json_encode(['content_type' => $contentType]));
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

    protected function staticCacheControl(string $targetPath, string $contentType, bool $isCdnRoute): string
    {
        if ($isCdnRoute) {
            $isJson = str_ends_with(strtolower($targetPath), '.json') || stripos($contentType, 'application/json') !== false;
            return $isJson ? 'public, max-age=86400' : 'public, max-age=604800, immutable';
        }

        return str_contains($contentType, 'javascript') ? 'no-store' : 'public, max-age=86400';
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
