<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Refatbd\LaravelChatGptMirror\Services\DynamicConfigService;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepares universal reverse-proxy, FastCGI/Apache/Nginx/LiteSpeed/Caddy/Octane requests:
 *  - Applies live DB runtime overrides (Redis enable/disable, proxy trust, HTTPS, SSE, Pool)
 *  - Restores Authorization header from REDIRECT_HTTP_AUTHORIZATION (Apache mod_rewrite/FastCGI quirk)
 *  - Temporarily trusts configured reverse-proxy X-Forwarded-* headers per request without leaking static state
 *  - Disables zlib output compression cleanly outside unit tests
 */
class PrepareMirrorRuntime
{
    public function __construct(
        protected ?DynamicConfigService $dynamicConfig = null
    ) {
        $this->dynamicConfig ??= (function_exists('app') && app()->bound(DynamicConfigService::class))
            ? app(DynamicConfigService::class)
            : new DynamicConfigService();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->dynamicConfig?->ensureApplied();
        $this->normalizeApacheAuthorizationHeader($request);

        if (!app()->runningUnitTests() && config('mirror.server.disable_output_compression', true)) {
            @ini_set('zlib.output_compression', '0');
        }

        if (!config('mirror.server.trust_proxies', true)) {
            return $next($request);
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
            return $next($request);
        } finally {
            SymfonyRequest::setTrustedProxies($prevProxies, $prevHeaders);
        }
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
