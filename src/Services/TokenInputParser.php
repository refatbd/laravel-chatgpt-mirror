<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Support\Carbon;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

/**
 * Extracts ChatGPT session tokens and companion metadata (`accessToken`, `expires`,
 * `user.email`, `user.name`, `account.id`, `account.planType`, `turnstileToken`)
 * from any pasted format: full `/api/auth/session` JSON (`token.txt`), browser
 * cookie exports, Netscape cookies, HTTP Cookie/Set-Cookie/header dumps, cURL
 * commands, or raw token strings.
 */
class TokenInputParser
{
    /**
     * Parse one or more session candidates from any raw pasted text or file content.
     *
     * @return array<int, array{
     *   session_token: ?string,
     *   access_token: ?string,
     *   expires_at: ?Carbon,
     *   email: ?string,
     *   name: ?string,
     *   account_id: ?string,
     *   plan_type: ?string,
     *   turnstile_token: ?string,
     *   source_format: string
     * }>
     */
    public function parseMany(string $raw): array
    {
        $clean = $this->stripBomAndCodeFences($raw);
        if ($clean === '') {
            return [];
        }

        // 1. Try parsing the entire payload as JSON (token.txt, arrays, cookie JSON, Playwright storageState)
        $jsonCandidates = $this->tryParseJsonPayload($clean);
        if ($jsonCandidates !== []) {
            return $this->deduplicateCandidates($jsonCandidates);
        }

        // 2. Check for embedded JSON object inside surrounding copy-pasted console/logs
        $embeddedJson = $this->tryExtractEmbeddedJson($clean);
        if ($embeddedJson !== []) {
            return $this->deduplicateCandidates($embeddedJson);
        }

        // 3. Check Netscape cookies.txt tab-separated table (handles multi-chunk .0/.1)
        $netscape = $this->tryParseNetscapeCookies($clean);
        if ($netscape !== null) {
            return [$netscape];
        }

        // 4. Multi-line NDJSON or multiple per-line tokens / Cookie headers
        $lines = preg_split('/\r\n|\r|\n/', $clean) ?: [];
        if (count($lines) > 1 && !$this->looksLikeSingleHttpHeaderBlockOrCurl($clean)) {
            $perLine = [];
            foreach ($lines as $line) {
                $t = trim($line);
                if ($t === '' || str_starts_with($t, '#')) {
                    continue;
                }
                $parsed = $this->parseSingleStructuredOrRaw($t);
                if ($parsed !== null) {
                    $perLine[] = $parsed;
                }
            }
            if ($perLine !== []) {
                return $this->deduplicateCandidates($perLine);
            }
        }

        // 5. Parse whole input as HTTP headers / cURL / Cookie header / key=value / raw token
        $single = $this->parseSingleStructuredOrRaw($clean);

        return $single !== null ? [$single] : [];
    }

    /**
     * Convenience helper returning the first extracted candidate.
     *
     * @return array{
     *   session_token: ?string,
     *   access_token: ?string,
     *   expires_at: ?Carbon,
     *   email: ?string,
     *   name: ?string,
     *   account_id: ?string,
     *   plan_type: ?string,
     *   turnstile_token: ?string,
     *   source_format: string
     * }|null
     */
    public function parse(string $raw): ?array
    {
        $many = $this->parseMany($raw);
        foreach ($many as $candidate) {
            if (!empty($candidate['session_token'])) {
                return $candidate;
            }
        }

        return $many[0] ?? null;
    }

    /**
     * Extract static session tokens from `.env` MIRROR_SESSION_TOKENS (supports JSON,
     * Cookie strings, OR classic comma-separated session tokens without shredding JSON commas).
     *
     * @return string[]
     */
    public function parseStaticTokens(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[') || str_contains($trimmed, '__Secure-next-auth.session-token')) {
            $parsed = $this->parseMany($trimmed);
            $extracted = array_values(array_filter(array_map(
                static fn (array $c): ?string => $c['session_token'] ?? null,
                $parsed
            )));
            if ($extracted !== []) {
                return $extracted;
            }
        }

        return array_values(array_filter(array_map('trim', explode(',', $trimmed))));
    }

    /**
     * Parse an already-decoded `/api/auth/session`-style associative array directly.
     *
     * @param array<string, mixed> $data
     * @return array{
     *   session_token: ?string,
     *   access_token: ?string,
     *   expires_at: ?Carbon,
     *   email: ?string,
     *   name: ?string,
     *   account_id: ?string,
     *   plan_type: ?string,
     *   turnstile_token: ?string,
     *   source_format: string
     * }|null
     */
    public function parseSessionArray(array $data): ?array
    {
        $candidates = $this->extractFromDecodedJson($data);
        return $candidates[0] ?? null;
    }

    protected function stripBomAndCodeFences(string $raw): string
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $clean = trim($clean);
        if ($clean === '') {
            return '';
        }

        if (preg_match('/^```(?:json|jsonc|txt|bash|sh|curl)?\s*\r?\n(.*?)\r?\n```$/is', $clean, $m)) {
            $clean = trim($m[1]);
        }

        return $clean;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function tryParseJsonPayload(string $text): array
    {
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->extractFromDecodedJson($decoded);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function tryExtractEmbeddedJson(string $text): array
    {
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $slice = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                $res = $this->extractFromDecodedJson($decoded);
                if ($res !== []) {
                    return $res;
                }
            }
        }

        $firstBracket = strpos($text, '[');
        $lastBracket = strrpos($text, ']');
        if ($firstBracket !== false && $lastBracket !== false && $lastBracket > $firstBracket) {
            $slice = substr($text, $firstBracket, $lastBracket - $firstBracket + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $this->extractFromDecodedJson($decoded);
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $decoded
     * @return array<int, array<string, mixed>>
     */
    protected function extractFromDecodedJson(array $decoded): array
    {
        // Playwright storageState `{ cookies: [...] }` or EditThisCookie array `[{name, value}, ...]`
        if (isset($decoded['cookies']) && is_array($decoded['cookies'])) {
            $fromCookies = $this->extractFromCookieList($decoded['cookies']);
            if ($fromCookies !== null) {
                return [$fromCookies];
            }
        }

        if (array_is_list($decoded)) {
            if ($this->looksLikeCookieObjectList($decoded)) {
                $fromCookies = $this->extractFromCookieList($decoded);
                return $fromCookies !== null ? [$fromCookies] : [];
            }

            $list = [];
            foreach ($decoded as $item) {
                if (is_string($item)) {
                    $single = $this->parseSingleStructuredOrRaw($item);
                    if ($single !== null) {
                        $list[] = $single;
                    }
                } elseif (is_array($item)) {
                    foreach ($this->extractFromDecodedJson($item) as $sub) {
                        $list[] = $sub;
                    }
                }
            }
            return $list;
        }

        // Wrapper lists: {"accounts": [...]}, {"tokens": [...]}, {"sessions": [...]}
        foreach (['accounts', 'tokens', 'sessions'] as $wrapperKey) {
            if (isset($decoded[$wrapperKey]) && is_array($decoded[$wrapperKey])) {
                $wrapped = $this->extractFromDecodedJson($decoded[$wrapperKey]);
                if ($wrapped !== []) {
                    return $wrapped;
                }
            }
        }

        // Direct cookie key/value map: {"__Secure-next-auth.session-token": "..."}
        $chunkedCookieMap = $this->extractFromKeyValueMap($decoded);
        if ($chunkedCookieMap !== null) {
            return [$chunkedCookieMap];
        }

        // Full `/api/auth/session` JSON dump (matching `token.txt`)
        $sessionToken = $this->firstNonEmptyString([
            $decoded['sessionToken'] ?? null,
            $decoded['session_token'] ?? null,
            $decoded['__Secure-next-auth.session-token'] ?? null,
            $decoded['__Host-next-auth.session-token'] ?? null,
            $decoded['next-auth.session-token'] ?? null,
            is_array($decoded['session'] ?? null) ? ($decoded['session']['sessionToken'] ?? $decoded['session']['token'] ?? null) : null,
        ]);

        // Unwrap nested Cookie string inside `sessionToken` field if user pasted a Cookie header there
        if ($sessionToken !== null && $this->containsCookieNameOrJson($sessionToken)) {
            $nested = $this->parse($sessionToken);
            if ($nested !== null && !empty($nested['session_token'])) {
                $sessionToken = $nested['session_token'];
            }
        }

        $accessToken = $this->firstNonEmptyString([
            $decoded['accessToken'] ?? null,
            $decoded['access_token'] ?? null,
        ]);

        if ($sessionToken === null && $accessToken === null) {
            return [];
        }

        $user = is_array($decoded['user'] ?? null) ? $decoded['user'] : [];
        $account = is_array($decoded['account'] ?? null) ? $decoded['account'] : [];

        $email = $this->firstNonEmptyString([
            $user['email'] ?? null,
            $decoded['email'] ?? null,
        ]);

        $name = $this->firstNonEmptyString([
            $decoded['name'] ?? null,
            $user['name'] ?? null,
            $decoded['label'] ?? null,
        ]);

        $accountId = $this->firstNonEmptyString([
            $account['id'] ?? null,
            $decoded['accountId'] ?? null,
            $decoded['account_id'] ?? null,
            $user['id'] ?? null,
        ]);

        $planType = $this->firstNonEmptyString([
            $account['planType'] ?? null,
            $decoded['planType'] ?? null,
            $decoded['plan_type'] ?? null,
            $user['planType'] ?? null,
        ]);

        $turnstileToken = $this->firstNonEmptyString([
            $decoded['turnstileToken'] ?? null,
            $decoded['turnstile_token'] ?? null,
            $decoded['cf-turnstile-response'] ?? null,
        ]);

        $expiresAt = $this->resolveExpiry(
            $accessToken,
            $decoded['expires'] ?? $decoded['expires_at'] ?? $decoded['access_token_expires_at'] ?? null
        );

        return [[
            'session_token' => $sessionToken !== null ? $this->cleanTokenString($sessionToken) : null,
            'access_token' => $accessToken !== null ? $this->cleanTokenString($accessToken) : null,
            'expires_at' => $expiresAt,
            'email' => $email,
            'name' => $name,
            'account_id' => $accountId,
            'plan_type' => $planType !== null ? strtolower($planType) : null,
            'turnstile_token' => $turnstileToken !== null ? $this->cleanTokenString($turnstileToken) : null,
            'source_format' => 'session_json',
        ]];
    }

    /**
     * @param array<int, mixed> $items
     */
    protected function looksLikeCookieObjectList(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && isset($item['name']) && array_key_exists('value', $item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, mixed> $cookies
     * @return array<string, mixed>|null
     */
    protected function extractFromCookieList(array $cookies): ?array
    {
        $map = [];
        $earliestExpiry = null;

        foreach ($cookies as $cookie) {
            if (!is_array($cookie)) {
                continue;
            }
            $cName = trim((string) ($cookie['name'] ?? ''));
            $cValue = (string) ($cookie['value'] ?? '');
            if ($cName === '' || $cValue === '') {
                continue;
            }
            $map[$cName] = $cValue;

            if ($this->isSessionCookieName($cName) && isset($cookie['expirationDate']) && is_numeric($cookie['expirationDate'])) {
                try {
                    $ts = (int) $cookie['expirationDate'];
                    if ($ts > time()) {
                        $earliestExpiry ??= Carbon::createFromTimestamp($ts);
                    }
                } catch (\Throwable) {
                }
            }
        }

        $assembled = $this->assembleSessionCookieFromMap($map);
        if ($assembled === null) {
            return null;
        }

        return [
            'session_token' => $assembled,
            'access_token' => null,
            'expires_at' => $earliestExpiry,
            'email' => null,
            'name' => null,
            'account_id' => null,
            'plan_type' => null,
            'turnstile_token' => null,
            'source_format' => 'cookie_json',
        ];
    }

    /**
     * @param array<mixed> $map
     * @return array<string, mixed>|null
     */
    protected function extractFromKeyValueMap(array $map): ?array
    {
        $stringMap = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $stringMap[$k] = $v;
            }
        }

        $assembled = $this->assembleSessionCookieFromMap($stringMap);
        if ($assembled === null) {
            return null;
        }

        return [
            'session_token' => $assembled,
            'access_token' => null,
            'expires_at' => null,
            'email' => null,
            'name' => null,
            'account_id' => null,
            'plan_type' => null,
            'turnstile_token' => null,
            'source_format' => 'cookie_json',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function tryParseNetscapeCookies(string $text): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $map = [];
        $expiry = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) >= 7) {
                $cookieName = trim($cols[5]);
                $cookieVal = trim($cols[6]);
                if ($cookieName !== '' && $cookieVal !== '' && $this->isSessionCookieName($cookieName)) {
                    $map[$cookieName] = $cookieVal;
                    if (ctype_digit(trim($cols[4])) && (int) trim($cols[4]) > time()) {
                        $expiry ??= Carbon::createFromTimestamp((int) trim($cols[4]));
                    }
                }
            }
        }

        $assembled = $this->assembleSessionCookieFromMap($map);
        if ($assembled === null) {
            return null;
        }

        return [
            'session_token' => $assembled,
            'access_token' => null,
            'expires_at' => $expiry,
            'email' => null,
            'name' => null,
            'account_id' => null,
            'plan_type' => null,
            'turnstile_token' => null,
            'source_format' => 'netscape_cookies',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseSingleStructuredOrRaw(string $text): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        // Single-line JSON object inside bulk text
        $asJson = $this->tryParseJsonPayload($trimmed);
        if ($asJson !== []) {
            return $asJson[0];
        }

        // Extract Cookie header / Set-Cookie / cURL `-H 'cookie: ...'` / `-b '...'` / `name=val` pairs
        $cookieMap = [];
        if (preg_match_all(
            '/(?:^|[;\s"\'(-])((?:__Secure-|__Host-)?next-auth\.session-token(?:\.\d+)?)\s*[:=]\s*([^;\s"\'\\\\`]+)/i',
            $trimmed,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $cName = $m[1];
                $cVal = $this->cleanTokenString($m[2]);
                if ($cVal !== '' && strtolower($cVal) !== 'deleted') {
                    $cookieMap[$cName] = $cVal;
                }
            }
        }

        $sessionFromCookie = $this->assembleSessionCookieFromMap($cookieMap);

        // Extract optional Authorization Bearer JWT & Turnstile header if present
        $accessToken = null;
        if (preg_match('/Authorization\s*[:=]\s*["\']?\s*Bearer\s+([A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+)/i', $trimmed, $authMatch)) {
            $accessToken = $this->cleanTokenString($authMatch[1]);
        }

        $turnstileToken = null;
        if (preg_match('/(?:turnstile[-_]?token|cf-turnstile-response)\s*[:=]\s*["\']?([^\s;"\',]+)/i', $trimmed, $tsMatch)) {
            $turnstileToken = $this->cleanTokenString($tsMatch[1]);
        }

        if ($sessionFromCookie !== null) {
            return [
                'session_token' => $sessionFromCookie,
                'access_token' => $accessToken,
                'expires_at' => $accessToken !== null ? $this->resolveExpiry($accessToken, null) : null,
                'email' => null,
                'name' => null,
                'account_id' => null,
                'plan_type' => null,
                'turnstile_token' => $turnstileToken,
                'source_format' => str_starts_with(strtolower($trimmed), 'curl ') ? 'curl' : 'cookie_header',
            ];
        }

        // Extract generic key=value line like `sessionToken=...`, `session_token=...`, `MIRROR_SESSION_TOKENS=...`
        if (preg_match('/^(?:MIRROR_SESSION_TOKENS?|session[_-]?token)\s*[:=]\s*(.+)$/is', $trimmed, $kvMatch)) {
            $val = $this->unquote(trim($kvMatch[1]));
            if ($val !== '' && !$this->containsCookieNameOrJson($val)) {
                return [
                    'session_token' => $this->cleanTokenString($val),
                    'access_token' => $accessToken,
                    'expires_at' => null,
                    'email' => null,
                    'name' => null,
                    'account_id' => null,
                    'plan_type' => null,
                    'turnstile_token' => $turnstileToken,
                    'source_format' => 'kv_line',
                ];
            }
            if ($val !== '') {
                return $this->parse($val);
            }
        }

        // Finally: standalone token string (strip surrounding quotes if pasted directly)
        $unquoted = $this->cleanTokenString($this->unquote($trimmed));
        if ($unquoted === '') {
            return null;
        }

        // Check if caller accidentally pasted ONLY an OpenAI/Auth0 Bearer JWT instead of sessionToken
        if ($this->looksLikeOpenAiAccessTokenJwt($unquoted)) {
            return [
                'session_token' => null,
                'access_token' => $unquoted,
                'expires_at' => $this->resolveExpiry($unquoted, null),
                'email' => null,
                'name' => null,
                'account_id' => null,
                'plan_type' => null,
                'turnstile_token' => null,
                'source_format' => 'raw_access_token',
            ];
        }

        return [
            'session_token' => $unquoted,
            'access_token' => null,
            'expires_at' => null,
            'email' => null,
            'name' => null,
            'account_id' => null,
            'plan_type' => null,
            'turnstile_token' => null,
            'source_format' => 'raw_session_token',
        ];
    }

    /**
     * Reassemble full or NextAuth chunked session cookies (`.0`, `.1`, `.2`...).
     *
     * @param array<string, string> $map
     */
    protected function assembleSessionCookieFromMap(array $map): ?string
    {
        $basePrefixes = [
            '__Secure-next-auth.session-token',
            '__Host-next-auth.session-token',
            'next-auth.session-token',
        ];

        foreach ($basePrefixes as $prefix) {
            foreach ($map as $name => $value) {
                if (strcasecmp($name, $prefix) === 0 && trim($value) !== '' && strtolower(trim($value)) !== 'deleted') {
                    return $this->cleanTokenString($value);
                }
            }

            $chunks = [];
            foreach ($map as $name => $value) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '\.(\d+)$/i', $name, $m)) {
                    $chunks[(int) $m[1]] = $this->cleanTokenString($value);
                }
            }
            if ($chunks !== []) {
                ksort($chunks, SORT_NUMERIC);
                $joined = implode('', $chunks);
                if ($joined !== '') {
                    return $joined;
                }
            }
        }

        return null;
    }

    protected function isSessionCookieName(string $name): bool
    {
        return (bool) preg_match('/^(?:__Secure-|__Host-)?next-auth\.session-token(?:\.\d+)?$/i', trim($name));
    }

    protected function containsCookieNameOrJson(string $text): bool
    {
        $t = ltrim($text);
        return str_starts_with($t, '{')
            || str_starts_with($t, '[')
            || (bool) preg_match('/next-auth\.session-token/i', $t);
    }

    protected function looksLikeSingleHttpHeaderBlockOrCurl(string $text): bool
    {
        $lower = strtolower($text);
        if (str_starts_with($lower, 'curl ')) {
            return true;
        }
        if (preg_match('/next-auth\.session-token\.0=/i', $text)) {
            return true;
        }

        $cookieCount = preg_match_all('/next-auth\.session-token\s*=/i', $text);
        return $cookieCount <= 1 && (str_contains($lower, 'cookie:') || str_contains($lower, 'set-cookie:'));
    }

    protected function looksLikeOpenAiAccessTokenJwt(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) < 80) {
            return false;
        }

        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return false;
        }

        $iss = (string) ($payload['iss'] ?? '');
        return isset($payload['https://api.openai.com/auth'])
            || str_contains($iss, 'auth.openai.com')
            || str_contains($iss, 'auth0.openai.com');
    }

    protected function cleanTokenString(string $value): string
    {
        $cleaned = trim($value, " \t\n\r\0\x0B\"';");
        if (preg_match('/%[0-9a-fA-F]{2}/', $cleaned)) {
            $decoded = rawurldecode($cleaned);
            if ($decoded !== '') {
                $cleaned = $decoded;
            }
        }

        return trim($cleaned);
    }

    protected function unquote(string $value): string
    {
        $t = trim($value);
        $len = strlen($t);
        if ($len >= 2) {
            $first = $t[0];
            $last = $t[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($t, 1, -1);
            }
        }
        return $t;
    }

    /**
     * @param array<int, mixed> $values
     */
    protected function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }

    protected function resolveExpiry(?string $accessToken, mixed $rawExpires): ?Carbon
    {
        if (is_string($accessToken) && trim($accessToken) !== '') {
            $jwtExp = MirrorToken::decodeJwtExpiry($accessToken);
            if ($jwtExp !== null && $jwtExp > 0) {
                return Carbon::createFromTimestamp($jwtExp);
            }
        }

        if (is_string($rawExpires) && trim($rawExpires) !== '') {
            try {
                return Carbon::parse($rawExpires);
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    protected function deduplicateCandidates(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $c) {
            $key = !empty($c['session_token'])
                ? 's:' . hash('sha256', (string) $c['session_token'])
                : (!empty($c['access_token']) ? 'a:' . hash('sha256', (string) $c['access_token']) : null);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $c;
        }
        return $unique;
    }
}
