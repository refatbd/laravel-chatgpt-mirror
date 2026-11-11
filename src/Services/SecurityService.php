<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Mirror;
use Refatbd\LaravelChatGptMirror\Models\MirrorSetting;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles API bearer authentication, HMAC-signed HttpOnly browser control cookies,
 * strict Host/Origin validation (without trusting spoofable Referer headers),
 * and asset-content link ticket signing.
 */
class SecurityService
{
    public const CONTROL_COOKIE_NAME = 'mirror_control';
    private const COOKIE_SECRET_KEY = 'mirror_control_hmac_secret';
    private const ASSET_GENERATION_KEY = 'mirror_asset_link_generation';

    /**
     * @return string[]
     */
    public function configuredApiKeys(): array
    {
        $raw = config('mirror.api_key', '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function isAllowedRequestHost(?string $hostHeader): bool
    {
        if (!$hostHeader) {
            return false;
        }

        $host = strtolower((string) parse_url('http://' . $hostHeader, PHP_URL_HOST));
        $host = trim($host, '[]');
        if ($host === '') {
            return false;
        }

        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }

        $appHost = strtolower((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
        if ($appHost !== '' && $host === $appHost) {
            return true;
        }

        $extraHosts = config('mirror.allowed_hosts', []);
        if (is_array($extraHosts)) {
            foreach ($extraHosts as $allowed) {
                if (is_string($allowed) && strtolower(trim($allowed)) === $host) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isAllowedOrigin(?string $originHeader, ?string $hostHeader): bool
    {
        if (!$originHeader || !$hostHeader) {
            return false;
        }

        $originHost = strtolower((string) parse_url($originHeader, PHP_URL_HOST));
        $originPort = parse_url($originHeader, PHP_URL_PORT);
        $reqHost = strtolower((string) parse_url('http://' . $hostHeader, PHP_URL_HOST));
        $reqPort = parse_url('http://' . $hostHeader, PHP_URL_PORT);

        if ($originHost === '' || $reqHost === '' || $originHost !== $reqHost) {
            return false;
        }

        if ($originPort !== null && $reqPort !== null && (int) $originPort !== (int) $reqPort) {
            return false;
        }

        return true;
    }

    public function bearerToken(Request $request): ?string
    {
        $authHeader = (string) ($request->header('Authorization')
            ?: $request->server('HTTP_AUTHORIZATION')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION')
            ?: '');

        if (stripos($authHeader, 'Bearer ') === 0) {
            $token = trim(substr($authHeader, 7));
            return $token !== '' ? $token : null;
        }

        $xApiKey = $request->header('X-API-Key');
        if (is_string($xApiKey) && trim($xApiKey) !== '') {
            return trim($xApiKey);
        }

        $queryKey = $request->query('api_key');
        if (is_string($queryKey) && trim($queryKey) !== '') {
            return trim($queryKey);
        }

        return null;
    }

    public function isSecureRequest(Request $request): bool
    {
        $forced = config('mirror.server.force_https');
        if ($forced === true || $forced === 'true' || $forced === '1' || $forced === 1) {
            return true;
        }
        if ($forced === false || $forced === 'false' || $forced === '0' || $forced === 0) {
            return false;
        }

        if ($request->isSecure()) {
            return true;
        }

        if (!config('mirror.server.trust_proxies', true)) {
            return false;
        }

        $proto = strtolower(trim(explode(',', (string) $request->header('X-Forwarded-Proto', ''))[0]));
        if ($proto === 'https') {
            return true;
        }

        if (strtolower((string) $request->header('Front-End-Https', '')) === 'on'
            || strtolower((string) $request->header('X-Forwarded-Ssl', '')) === 'on') {
            return true;
        }

        $cfVisitor = (string) $request->header('CF-Visitor', '');
        if ($cfVisitor !== '' && str_contains($cfVisitor, '"scheme":"https"')) {
            return true;
        }

        $rfcForwarded = (string) $request->header('Forwarded', '');
        if ($rfcForwarded !== '' && preg_match('/(?:^|[;,\s])proto\s*=\s*"?https"?/i', $rfcForwarded) === 1) {
            return true;
        }

        return false;
    }

    public function resolveOrigin(Request $request): string
    {
        $scheme = $this->isSecureRequest($request) ? 'https' : $request->getScheme();
        $trustProxies = (bool) config('mirror.server.trust_proxies', true);

        $forwardedHost = $trustProxies
            ? trim(explode(',', (string) $request->header('X-Forwarded-Host', ''))[0])
            : '';

        if ($forwardedHost === '' && $trustProxies) {
            $rfcForwarded = (string) $request->header('Forwarded', '');
            if ($rfcForwarded !== '' && preg_match('/(?:^|[;,\s])host\s*=\s*"?([^";,\s]+)"?/i', $rfcForwarded, $m) === 1) {
                $forwardedHost = trim($m[1]);
            }
        }

        $host = $forwardedHost !== '' ? $forwardedHost : $request->getHttpHost();

        if ($host === '') {
            $appUrl = rtrim((string) config('app.url', ''), '/');
            return $appUrl !== '' ? $appUrl : ($scheme . '://localhost');
        }

        if ($trustProxies && !str_contains($host, ':')) {
            $forwardedPort = trim(explode(',', (string) $request->header('X-Forwarded-Port', ''))[0]);
            if ($forwardedPort !== '' && ctype_digit($forwardedPort)) {
                $portInt = (int) $forwardedPort;
                if (($scheme === 'https' && $portInt !== 443) || ($scheme === 'http' && $portInt !== 80)) {
                    $host .= ':' . $portInt;
                }
            }
        }

        return $scheme . '://' . $host;
    }

    /**
     * Resolve sub-path prefix when hosted under a subdirectory or behind X-Forwarded-Prefix.
     */
    public function resolveBasePath(Request $request): string
    {
        $trustProxies = (bool) config('mirror.server.trust_proxies', true);
        if ($trustProxies) {
            $forwardedPrefix = trim(explode(',', (string) $request->header('X-Forwarded-Prefix', ''))[0]);
            if ($forwardedPrefix !== '' && $forwardedPrefix !== '/') {
                return '/' . trim($forwardedPrefix, '/');
            }
        }

        $baseUrl = rtrim($request->getBaseUrl(), '/');
        if ($baseUrl !== '' && str_ends_with($baseUrl, '/index.php')) {
            $baseUrl = rtrim(substr($baseUrl, 0, -strlen('/index.php')), '/');
        }

        return $baseUrl;
    }

    public function resolveBaseUrl(Request $request): string
    {
        return rtrim($this->resolveOrigin($request) . $this->resolveBasePath($request), '/');
    }

    /**
     * Universal unbuffered SSE headers honored by Apache, Nginx, LiteSpeed, Caddy, and CDN proxies.
     *
     * @return array<string, string>
     */
    public function streamingHeaders(string $requestId = ''): array
    {
        $headers = [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, no-transform',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'X-LiteSpeed-Cache-Control' => 'no-cache',
            'Content-Encoding' => 'none',
        ];

        if ($requestId !== '') {
            $headers['X-Request-Id'] = $requestId;
        }

        return $headers;
    }

    public function matchesApiKey(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        foreach ($this->configuredApiKeys() as $configured) {
            if (hash_equals($configured, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function issueControlCookieValue(): string
    {
        return hash_hmac('sha256', 'mirror-browser-control-v1', $this->hmacSecret());
    }

    public function hasValidControlCookie(Request $request): bool
    {
        $cookie = $request->cookie(self::CONTROL_COOKIE_NAME);
        if (!is_string($cookie) || $cookie === '') {
            return false;
        }

        // Fast equality check against expected signed token.
        if (!hash_equals($this->issueControlCookieValue(), $cookie)) {
            return false;
        }

        // For state-changing browser requests carrying an Origin header, enforce matching Origin.
        $origin = $request->header('Origin');
        if (is_string($origin) && $origin !== '') {
            return $this->isAllowedOrigin($origin, $request->header('Host'));
        }

        return true;
    }

    public function attachControlCookie(Response $response, Request $request): Response
    {
        $cookie = Cookie::create(
            self::CONTROL_COOKIE_NAME,
            $this->issueControlCookieValue(),
            now()->addDays(30)->getTimestamp(),
            '/',
            null,
            $this->isSecureRequest($request),
            true,
            false,
            Cookie::SAMESITE_LAX
        );

        $response->headers->setCookie($cookie);
        return $response;
    }

    public function authorizePublicApi(Request $request): bool
    {
        $customAuthenticator = Mirror::resolveApiKeyAuthenticator();
        if ($customAuthenticator !== null) {
            $bearer = $this->bearerToken($request);
            if ($bearer !== null) {
                $authResult = $customAuthenticator($bearer, $request);
                if ($authResult !== false && $authResult !== null) {
                    $request->attributes->set('mirror.auth_context', $authResult);
                    return true;
                }

                if ($this->matchesApiKey($bearer)) {
                    $request->attributes->set('mirror.auth_context', ['type' => 'static_api_key']);
                    return true;
                }

                return false;
            }

            if ($this->hasValidControlCookie($request)) {
                $request->attributes->set('mirror.auth_context', ['type' => 'control_cookie']);
                return true;
            }

            return false;
        }

        if ($this->configuredApiKeys() === []) {
            return true;
        }

        if ($this->matchesApiKey($this->bearerToken($request))) {
            $request->attributes->set('mirror.auth_context', ['type' => 'static_api_key']);
            return true;
        }

        // Do NOT trust spoofable Referer headers; allow browser UI calls only via signed HttpOnly cookie.
        if ($this->hasValidControlCookie($request)) {
            $request->attributes->set('mirror.auth_context', ['type' => 'control_cookie']);
            return true;
        }

        return false;
    }

    public function authorizeAdmin(Request $request): bool
    {
        $authorizer = Mirror::resolveAdminAuthorizer();
        if ($authorizer === null) {
            return true;
        }

        return (bool) $authorizer($request);
    }

    public function authorizeControlEndpoint(Request $request): bool
    {
        if ($this->configuredApiKeys() === []) {
            return true;
        }

        if ($this->matchesApiKey($this->bearerToken($request))) {
            return true;
        }

        return $this->hasValidControlCookie($request);
    }

    public function assetLinkGeneration(): string
    {
        try {
            $gen = MirrorSetting::getValue(self::ASSET_GENERATION_KEY);
            if ($gen) {
                return $gen;
            }
            $gen = Str::random(24);
            MirrorSetting::setValue(self::ASSET_GENERATION_KEY, $gen);
            return $gen;
        } catch (\Throwable) {
            return substr(hash('sha256', (string) config('app.key', 'mirror')), 0, 24);
        }
    }

    public function rotateAssetLinkGeneration(): string
    {
        $gen = Str::random(24);
        try {
            MirrorSetting::setValue(self::ASSET_GENERATION_KEY, $gen);
        } catch (\Throwable) {
        }
        return $gen;
    }

    public function hmacSecret(): string
    {
        $configured = config('mirror.asset_ticket_secret', '');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appKey = (string) config('app.key', '');
        if ($appKey !== '') {
            return hash('sha256', 'mirror-secret|' . $appKey);
        }

        try {
            $stored = MirrorSetting::getValue(self::COOKIE_SECRET_KEY);
            if ($stored) {
                return $stored;
            }
            $generated = bin2hex(random_bytes(32));
            MirrorSetting::setValue(self::COOKIE_SECRET_KEY, $generated);
            return $generated;
        } catch (\Throwable) {
            return hash('sha256', __DIR__);
        }
    }

    /**
     * @return array{error: array{message: string, type: string, param: ?string, code: string, request_id: ?string}}
     */
    public function apiErrorEnvelope(int $status, string $message, ?string $requestId = null, ?string $code = null): array
    {
        $resolvedCode = $code ?? match ($status) {
            400 => 'bad_request',
            401 => 'invalid_api_key',
            403 => 'forbidden',
            404 => 'not_found',
            409 => 'conflict',
            429 => 'rate_limit_exceeded',
            default => $status >= 500 ? 'upstream_error' : 'invalid_request_error',
        };

        return [
            'error' => [
                'message' => $message,
                'type' => $status === 401 || $status === 400 ? 'invalid_request_error' : 'api_error',
                'param' => null,
                'code' => $resolvedCode,
                'request_id' => $requestId,
            ],
        ];
    }
}
