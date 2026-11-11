<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Exceptions\TokenPoolExhaustedException;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Models\MirrorSetting;

/**
 * Records sanitized failure metadata (no credentials or raw prompts) for /api/diagnostics
 * and classifies ChatGPT Web protocol errors for debugging protocol drift.
 */
class FailureTracker
{
    private const STORAGE_KEY = 'failure_tracker_recent';
    private const CACHE_KEY = 'failures:recent';
    private const MAX_ENTRIES = 10;

    protected MirrorCache $cache;

    public function __construct(?MirrorCache $cache = null)
    {
        $this->cache = $cache ?? new MirrorCache();
    }

    public function record(string $code, ?string $requestId = null, ?string $protocolCategory = null): void
    {
        try {
            $entries = $this->recent();
            array_unshift($entries, [
                'time' => now()->toIso8601String(),
                'code' => $code,
                'requestId' => $requestId ?: (string) Str::uuid(),
                'protocolCategory' => $protocolCategory,
            ]);
            $trimmed = array_slice($entries, 0, self::MAX_ENTRIES);
            $this->cache->put(self::CACHE_KEY, $trimmed, 3600);
            MirrorSetting::setJson(self::STORAGE_KEY, $trimmed);
        } catch (\Throwable) {
            // Never let diagnostic persistence crash an API response.
        }
    }

    /**
     * @return array<int, array{time: string, code: string, requestId: string, protocolCategory: ?string}>
     */
    public function recent(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return array_values(array_filter($cached, fn ($row) => is_array($row) && isset($row['code'])));
        }

        $raw = MirrorSetting::getJson(self::STORAGE_KEY, []);
        $filtered = array_values(array_filter($raw, fn ($row) => is_array($row) && isset($row['code'])));
        $this->cache->put(self::CACHE_KEY, $filtered, 3600);
        return $filtered;
    }

    public function clear(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        MirrorSetting::setJson(self::STORAGE_KEY, []);
    }

    public function classifyProtocolFailure(\Throwable $error): ?string
    {
        $msg = strtolower($error->getMessage());

        if ($error instanceof TokenPoolExhaustedException) {
            return 'no_healthy_token';
        }
        if (str_contains($msg, 'turnstile')) {
            return 'turnstile_required';
        }
        if (str_contains($msg, 'sentinel') || str_contains($msg, 'proof')) {
            return 'sentinel_challenge_failed';
        }
        if (str_contains($msg, 'conduit') || str_contains($msg, 'prepare')) {
            return 'conduit_prepare_failed';
        }
        if ($error instanceof UpstreamRequestException) {
            $status = $error->getCode();
            if ($status === 401 || $status === 403) {
                return 'upstream_auth_or_cf_blocked';
            }
            if ($status === 429) {
                return 'upstream_rate_limited';
            }
            return 'upstream_http_' . $status;
        }
        if (str_contains($msg, 'sse') || str_contains($msg, 'stream')) {
            return 'stream_parse_error';
        }

        return null;
    }
}
