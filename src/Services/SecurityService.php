<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $authHeader = (string) $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            return $token !== '' ? $token : null;
        }

        $queryKey = $request->query('api_key');
        if (is_string($queryKey) && trim($queryKey) !== '') {
            return trim($queryKey);
        }

        return null;
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
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );

        $response->headers->setCookie($cookie);
        return $response;
    }

    public function authorizePublicApi(Request $request): bool
    {
        if ($this->configuredApiKeys() === []) {
            return true;
        }

        if ($this->matchesApiKey($this->bearerToken($request))) {
            return true;
        }

        // Do NOT trust spoofable Referer headers; allow browser UI calls only via signed HttpOnly cookie.
        return $this->hasValidControlCookie($request);
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
