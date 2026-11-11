<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

/**
 * Exchanges `__Secure-next-auth.session-token` via ChatGPT's NextAuth endpoint
 * to mint fresh Bearer access tokens, captures Set-Cookie session rotation,
 * and decodes JWT `exp` claims accurately.
 */
class TokenRefresher
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('mirror.upstream', 'https://chatgpt.com'),
            'timeout' => (float) config('mirror.http.timeout', 30),
            'connect_timeout' => (float) config('mirror.http.connect_timeout', 10),
            'verify' => (bool) config('mirror.http.verify_ssl', true),
            'proxy' => config('mirror.http.proxy'),
            'http_errors' => false,
            'headers' => [
                'User-Agent' => (string) config('mirror.http.user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36'),
                'Accept' => 'application/json',
                'Referer' => rtrim((string) config('mirror.upstream', 'https://chatgpt.com'), '/') . '/',
            ],
        ]);
    }

    /**
     * Verify a raw candidate session token against upstream /api/auth/session + /backend-api/me
     * before storing it in the token pool.
     *
     * @return array{
     *   session_token: string,
     *   access_token: string,
     *   expires_at: ?Carbon,
     *   email: ?string,
     *   name: ?string,
     *   account_id: ?string,
     *   plan_type: ?string
     * }|null
     */
    public function verifyCandidateSessionToken(string $sessionToken): ?array
    {
        $cleanToken = trim($sessionToken);
        if ($cleanToken === '') {
            return null;
        }

        try {
            $response = $this->http->get('/api/auth/session', [
                'headers' => [
                    'Cookie' => '__Secure-next-auth.session-token=' . $cleanToken,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $rotated = $this->extractRotatedSessionToken($response->getHeader('Set-Cookie'));
            $persistedSession = $rotated ?: $cleanToken;

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || empty($data['accessToken'])) {
                return null;
            }

            $accessToken = (string) $data['accessToken'];
            $expiresAt = $this->resolveExpiry($accessToken, $data['expires'] ?? null);
            $user = is_array($data['user'] ?? null) ? $data['user'] : [];
            $account = is_array($data['account'] ?? null) ? $data['account'] : [];

            $email = isset($user['email']) ? (string) $user['email'] : null;
            $name = isset($user['name']) ? (string) $user['name'] : null;
            $accountId = $account['id'] ?? $user['id'] ?? $data['accountId'] ?? null;
            $planType = isset($account['planType']) ? (string) $account['planType'] : ($user['planType'] ?? null);

            // Confirm via /backend-api/me to resolve accurate accountId/email when available.
            $me = $this->fetchMe($accessToken, $accountId ? (string) $accountId : null);
            if (is_array($me)) {
                $email = $email ?: (isset($me['email']) ? (string) $me['email'] : null);
                $name = $name ?: (isset($me['name']) ? (string) $me['name'] : null);
                if (isset($me['accounts']) && is_array($me['accounts'])) {
                    foreach ($me['accounts'] as $key => $acct) {
                        if ($key !== 'default' && is_array($acct) && !empty($acct['account']['account_id'])) {
                            $accountId = (string) $acct['account']['account_id'];
                            $planType = $planType ?: (isset($acct['entitlement']['subscription_plan']) ? (string) $acct['entitlement']['subscription_plan'] : null);
                            break;
                        }
                    }
                }
                if (!$accountId && !empty($me['id'])) {
                    $accountId = (string) $me['id'];
                }
            }

            return [
                'session_token' => $persistedSession,
                'access_token' => $accessToken,
                'expires_at' => $expiresAt,
                'email' => $email,
                'name' => $name,
                'account_id' => $accountId ? (string) $accountId : null,
                'plan_type' => $planType ? (string) $planType : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Mirror candidate session verification error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Refresh an existing MirrorToken instance using its encrypted session_token.
     */
    public function refresh(MirrorToken $token): bool
    {
        $sessionToken = $token->session_token;
        if (empty($sessionToken)) {
            $token->update([
                'status' => 'expired',
                'last_error' => 'Missing session token for refresh',
            ]);
            return false;
        }

        try {
            $response = $this->http->get('/api/auth/session', [
                'headers' => [
                    'Cookie' => '__Secure-next-auth.session-token=' . $sessionToken,
                ],
            ]);

            $status = $response->getStatusCode();
            $rotated = $this->extractRotatedSessionToken($response->getHeader('Set-Cookie'));
            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if ($status !== 200 || !is_array($data) || empty($data['accessToken'])) {
                Log::warning('Mirror TokenRefresher failed', [
                    'token_id' => $token->id,
                    'status' => $status,
                ]);

                if ($status === 401 || $status === 403 || (is_array($data) && empty($data['accessToken']))) {
                    $token->update([
                        'status' => 'expired',
                        'last_error' => "Upstream session refresh failed with HTTP {$status}",
                    ]);
                }

                return false;
            }

            $accessToken = (string) $data['accessToken'];
            $expiresAt = $this->resolveExpiry($accessToken, $data['expires'] ?? null);
            $user = is_array($data['user'] ?? null) ? $data['user'] : [];
            $account = is_array($data['account'] ?? null) ? $data['account'] : [];

            $updates = [
                'access_token' => $accessToken,
                'access_token_expires_at' => $expiresAt,
                'email' => $user['email'] ?? $token->email,
                'account_id' => $account['id'] ?? $user['id'] ?? $token->account_id,
                'plan_type' => $account['planType'] ?? $token->plan_type ?? 'free',
                'status' => 'active',
                'consecutive_errors' => 0,
                'cooldown_until' => null,
                'last_refreshed_at' => Carbon::now(),
                'last_error' => null,
            ];

            if ($rotated !== null && $rotated !== $sessionToken) {
                $updates['session_token'] = $rotated;
            }

            $token->update($updates);
            return true;
        } catch (\Throwable $e) {
            Log::error('Mirror TokenRefresher exception', [
                'token_id' => $token->id,
                'error' => $e->getMessage(),
            ]);

            $token->update(['last_error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchMe(string $accessToken, ?string $accountId = null): ?array
    {
        try {
            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
            ];
            if ($accountId) {
                $headers['ChatGPT-Account-ID'] = $accountId;
            }

            $res = $this->http->get('/backend-api/me', ['headers' => $headers]);
            if ($res->getStatusCode() !== 200) {
                return null;
            }

            $decoded = json_decode((string) $res->getBody(), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract rotated `__Secure-next-auth.session-token` value from Set-Cookie headers.
     *
     * @param string[] $setCookieHeaders
     */
    public function extractRotatedSessionToken(array $setCookieHeaders): ?string
    {
        foreach ($setCookieHeaders as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (preg_match('/(?:^|;\s*)__Secure-next-auth\.session-token=([^;]+)/i', $header, $matches)) {
                $value = trim(urldecode($matches[1]));
                if ($value !== '' && strtolower($value) !== 'deleted') {
                    return $value;
                }
            }
        }

        return null;
    }

    protected function resolveExpiry(string $accessToken, mixed $rawExpires): Carbon
    {
        $jwtExp = MirrorToken::decodeJwtExpiry($accessToken);
        if ($jwtExp !== null && $jwtExp > time()) {
            return Carbon::createFromTimestamp($jwtExp);
        }

        if (is_string($rawExpires) && trim($rawExpires) !== '') {
            try {
                return Carbon::parse($rawExpires);
            } catch (\Throwable) {
            }
        }

        return Carbon::now()->addHours(12);
    }
}
