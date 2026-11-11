<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\UrlRewriter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxies the ChatGPT web UI and static bundles through Mirror cleanly:
 * strips blocking security headers, rewrites upstream origins, sanitizes SSR tokens,
 * fixes POST Content-Type forwarding, and preserves `/` UI parity cleanly.
 */
class BrowserProxyController extends Controller
{
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
        ?MirrorCache $cache = null
    ) {
        $this->patcher = $patcher;
        $this->rewriter = $rewriter;
        $this->pool = $pool;
        $this->security = $security;
        $this->cache = $cache ?? (app()->bound(MirrorCache::class) ? app(MirrorCache::class) : new MirrorCache());

        $this->http = new Client([
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
        if (str_starts_with($request->path(), 'ces/noop')) {
            return response('', 204);
        }

        $targetPath = '/' . ltrim($path, '/');
        if ($targetPath === '/' && $request->path() !== '/') {
            $targetPath = '/' . ltrim($request->path(), '/');
        }

        if (str_starts_with($targetPath, '/cdn/')) {
            $targetPath = substr($targetPath, 4);
            $baseOverride = 'https://cdn.oaistatic.com';
        } else {
            $baseOverride = null;
        }

        $query = $request->getQueryString();
        $fullPath = $targetPath . ($query ? '?' . $query : '');
        $localOrigin = $this->security->resolveOrigin($request);

        // Origin-scoped Redis + disk cache for immutable static bundles so HTTP/HTTPS/multi-host rewrites never collide.
        $isStaticAsset = preg_match('#\.(js|css|woff2?|png|jpg|jpeg|gif|svg|ico|json|webp)$#i', $targetPath) === 1;
        $assetsInRedis = (bool) config('mirror.cache.assets_in_redis', true);
        $assetsTtl = max(60, (int) config('mirror.cache.assets_ttl', 86400));
        $assetsMaxBytes = max(1024, (int) config('mirror.cache.assets_redis_max_bytes', 262144));

        if ($request->isMethod('get') && $isStaticAsset && !str_starts_with($targetPath, '/api/')) {
            $cacheKey = md5($localOrigin . '|' . ($baseOverride ?: '') . $fullPath);
            $redisKey = 'asset:' . $cacheKey;

            if ($assetsInRedis) {
                $cached = $this->cache->get($redisKey);
                if (is_array($cached) && isset($cached['body_b64']) && is_string($cached['body_b64'])) {
                    $decoded = base64_decode($cached['body_b64'], true);
                    if (is_string($decoded)) {
                        $ct = (string) ($cached['content_type'] ?? $this->guessMimeType($targetPath));
                        return response($decoded, 200, [
                            'Content-Type' => $ct,
                            'Cache-Control' => str_contains($ct, 'javascript') ? 'no-store' : 'public, max-age=86400',
                            'Alt-Svc' => 'clear',
                        ]);
                    }
                }
            }

            $cacheDir = storage_path('framework/mirror-assets');
            $cacheFile = $cacheDir . '/' . $cacheKey;
            $metaFile = $cacheDir . '/' . $cacheKey . '.meta.json';

            if (File::exists($cacheFile) && File::exists($metaFile)) {
                $meta = json_decode((string) File::get($metaFile), true) ?: [];
                $ct = (string) ($meta['content_type'] ?? $this->guessMimeType($targetPath));
                return response(File::get($cacheFile), 200, [
                    'Content-Type' => $ct,
                    'Cache-Control' => str_contains($ct, 'javascript') ? 'no-store' : 'public, max-age=86400',
                    'Alt-Svc' => 'clear',
                ]);
            }
        }

        $token = $this->pool->acquire();
        $upstreamHost = parse_url($baseOverride ?: (string) config('mirror.upstream', 'https://chatgpt.com'), PHP_URL_HOST) ?: 'chatgpt.com';

        $headers = [
            'User-Agent' => (string) config('mirror.http.user_agent', $request->userAgent() ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
            'Accept' => (string) $request->header('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
            'Accept-Language' => 'en-US,en;q=0.9',
            'Origin' => 'https://' . $upstreamHost,
            'Referer' => 'https://' . $upstreamHost . '/',
        ];

        $cookies = [];
        if ($token && $token->session_token) {
            $cookies[] = '__Secure-next-auth.session-token=' . $token->session_token;
        }
        $clientCookie = $request->header('Cookie');
        if ($clientCookie) {
            // Strip local mirror_control cookie before forwarding upstream.
            $filtered = preg_replace('/(?:^|;\s*)mirror_control=[^;]*/i', '', $clientCookie);
            $filtered = trim((string) $filtered, "; \t");
            if ($filtered !== '') {
                $cookies[] = $filtered;
            }
        }
        if ($cookies !== []) {
            $headers['Cookie'] = implode('; ', $cookies);
        }

        if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
            $headers['Content-Type'] = (string) $request->header('Content-Type', 'application/json');
        }

        try {
            $options = [
                'headers' => $headers,
            ];
            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
                $options['body'] = $request->getContent();
            }

            $upstreamUrl = $baseOverride ? ($baseOverride . $fullPath) : $fullPath;
            $response = $this->http->request($request->method(), $upstreamUrl, $options);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            $rawHeaders = [];
            foreach ($response->getHeaders() as $k => $vals) {
                $rawHeaders[$k] = $vals[0] ?? '';
            }

            // Rewrite Location redirects back onto local mirror host cleanly.
            if (isset($rawHeaders['Location']) || isset($rawHeaders['location'])) {
                $loc = $rawHeaders['Location'] ?? $rawHeaders['location'];
                $rawHeaders['Location'] = $this->rewriter->rewriteText($loc, $localOrigin);
                unset($rawHeaders['location']);
            }

            $cleanedHeaders = $this->patcher->cleanHeaders($rawHeaders);
            $cleanedHeaders['Alt-Svc'] = 'clear';
            $contentType = (string) ($cleanedHeaders['Content-Type'] ?? $cleanedHeaders['content-type'] ?? $this->guessMimeType($targetPath));

            if (stripos($contentType, 'text/html') !== false) {
                $body = $this->patcher->patchHtml($body, $localOrigin);
                $cleanedHeaders['Cache-Control'] = 'no-store';
                $resp = response($body, $status, $cleanedHeaders);
                return $this->security->attachControlCookie($resp, $request);
            }

            if (
                stripos($contentType, 'javascript') !== false ||
                stripos($contentType, 'text/css') !== false ||
                stripos($contentType, 'application/json') !== false
            ) {
                $body = $this->rewriter->rewriteText($body, $localOrigin, $contentType);
                $cleanedHeaders['Cache-Control'] = 'no-store';
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
            if ($token) {
                $this->pool->release($token, true);
            }
        }
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
