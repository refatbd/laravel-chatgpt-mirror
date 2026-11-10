<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;
use Throwable;

class TokenRefresher
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('mirror.upstream', 'https://chatgpt.com'),
            'timeout' => (float) config('mirror.egress.timeout', 30),
            'connect_timeout' => (float) config('mirror.egress.connect_timeout', 10),
            'verify' => (bool) config('mirror.egress.verify_ssl', true),
            'proxy' => config('mirror.egress.proxy'),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
                'Accept' => 'application/json',
                'Referer' => 'https://chatgpt.com/',
            ],
        ]);
    }

    /**
     * Refresh the given token by querying chatgpt.com/api/auth/session.
     *
     * @throws UpstreamRequestException
     */
    public function refresh(MirrorToken $token): bool
    {
        $sessionToken = $token->session_token;

        if (empty($sessionToken)) {
            Log::warning("Mirror: Token #{$token->id} has no session_token configured.");
            return false;
        }

        try {
            $response = $this->http->get('/api/auth/session', [
                'headers' => [
                    'Cookie' => "__Secure-next-auth.session-token={$sessionToken}",
                    'Authorization' => "Bearer {$sessionToken}",
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            if (empty($body['accessToken'])) {
                Log::error("Mirror: Received empty accessToken from auth/session for token #{$token->id}.", $body ?? []);
                $token->update(['status' => 'expired']);
                return false;
            }

            $expiresAt = isset($body['expires'])
                ? Carbon::parse($body['expires'])
                : Carbon::now()->addDays(10);

            $planType = $body['account']['planType'] ?? 'free';
            $accountId = $body['account']['id'] ?? ($body['user']['id'] ?? null);
            $email = $body['user']['email'] ?? $token->email;

            $token->update([
                'access_token' => $body['accessToken'],
                'access_token_expires_at' => $expiresAt,
                'email' => $email,
                'plan_type' => $planType,
                'account_id' => $accountId,
                'status' => 'active',
                'last_refreshed_at' => Carbon::now(),
                'meta' => array_merge($token->meta ?? [], ['user' => $body['user'] ?? null]),
            ]);

            return true;
        } catch (GuzzleException $e) {
            $status = $e->getCode();
            if ($status === 401 || $status === 403) {
                $token->update(['status' => 'expired']);
            }
            Log::error("Mirror: Failed to refresh token #{$token->id}: " . $e->getMessage());
            throw new UpstreamRequestException("Failed to refresh session token: " . $e->getMessage(), (int) $status ?: 502);
        } catch (Throwable $e) {
            Log::error("Mirror: Unexpected error refreshing token #{$token->id}: " . $e->getMessage());
            throw new UpstreamRequestException("Unexpected token refresh error: " . $e->getMessage(), 500);
        }
    }
}
