<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Refatbd\LaravelChatGptMirror\Contracts\TokenPoolInterface;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

class TokenPoolManager implements TokenPoolInterface
{
    public function __construct(
        protected TokenRefresher $refresher
    ) {}

    /**
     * Acquire the best available healthy token for a request.
     */
    public function acquire(?string $preferredTokenId = null): ?MirrorToken
    {
        return $this->acquireForAccount($preferredTokenId, null);
    }

    /**
     * Acquire a healthy token, optionally pinned to a specific token ID or account ID.
     */
    public function acquireForAccount(?string $preferredTokenId = null, ?string $accountId = null): ?MirrorToken
    {
        $this->releaseExpiredCooldowns();

        $maxConcurrent = (int) config('mirror.pool.max_concurrent_per_token', 5);
        $strategy = config('mirror.pool.strategy', 'least_busy');

        if ($preferredTokenId !== null && $preferredTokenId !== '') {
            $preferred = MirrorToken::find($preferredTokenId);
            if ($preferred && $preferred->isHealthy() && $preferred->active_requests < $maxConcurrent) {
                if ($this->ensureTokenFreshness($preferred)) {
                    $preferred->incrementActive();
                    return $preferred;
                }
            }
        }

        $this->syncStaticEnvTokens();

        $query = MirrorToken::whereIn('status', ['active', 'cooldown'])
            ->where(function ($q): void {
                $q->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', Carbon::now());
            })
            ->where('active_requests', '<', $maxConcurrent);

        if ($accountId !== null && $accountId !== '' && $accountId !== 'default') {
            $query->where('account_id', $accountId);
        }

        $tokens = $query->get();

        // Fallback if specific account query had no matches (e.g. legacy null account_id token).
        if ($tokens->isEmpty() && $accountId !== null && $accountId !== '' && $accountId !== 'default') {
            $tokens = MirrorToken::whereIn('status', ['active', 'cooldown'])
                ->where(function ($q): void {
                    $q->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', Carbon::now());
                })
                ->where('active_requests', '<', $maxConcurrent)
                ->get();
        }

        if ($tokens->isEmpty()) {
            return null;
        }

        $candidate = $strategy === 'round_robin'
            ? $this->selectRoundRobin($tokens)
            : $this->selectLeastBusy($tokens);

        if (!$candidate) {
            return null;
        }

        if (!$this->ensureTokenFreshness($candidate)) {
            $fallback = $tokens->where('id', '!=', $candidate->id)->first(function (MirrorToken $t) {
                return $this->ensureTokenFreshness($t);
            });

            if (!$fallback) {
                return null;
            }
            $candidate = $fallback;
        }

        $candidate->incrementActive();
        return $candidate;
    }

    /**
     * Resolve the active primary accountId (for scoping /api/conversations, /api/files, etc.).
     */
    public function primaryAccountId(): string
    {
        $this->syncStaticEnvTokens();

        $active = MirrorToken::where('status', 'active')
            ->orderByDesc('last_used_at')
            ->orderBy('id')
            ->first();

        if ($active) {
            return $active->getEffectiveAccountId();
        }

        $any = MirrorToken::orderBy('id')->first();
        return $any ? $any->getEffectiveAccountId() : 'default';
    }

    protected function selectLeastBusy(Collection $tokens): ?MirrorToken
    {
        return $tokens->sortBy([
            ['active_requests', 'asc'],
            ['consecutive_errors', 'asc'],
            ['last_used_at', 'asc'],
        ])->first();
    }

    protected function selectRoundRobin(Collection $tokens): ?MirrorToken
    {
        $sorted = $tokens->sortBy('id')->values();
        if ($sorted->isEmpty()) {
            return null;
        }

        $lastIndex = Cache::get('mirror.pool.rr_index', -1);
        $nextIndex = ($lastIndex + 1) % $sorted->count();
        Cache::put('mirror.pool.rr_index', $nextIndex, 3600);

        return $sorted->get($nextIndex);
    }

    public function release(MirrorToken $token, bool $success = true, ?int $tokensUsed = null): void
    {
        $token->decrementActive($success, $tokensUsed);
    }

    public function putInCooldown(MirrorToken $token, ?int $seconds = null): void
    {
        $duration = $seconds ?? (int) config('mirror.pool.cooldown_seconds', 60);
        Log::warning("Placing MirrorToken #{$token->id} ({$token->name}) in cooldown for {$duration}s");
        $token->enterCooldown($duration);
    }

    public function ensureTokenFreshness(MirrorToken $token): bool
    {
        if (!$token->needsRefresh()) {
            return true;
        }

        if (empty($token->session_token)) {
            return !empty($token->access_token);
        }

        return $this->refresher->refresh($token);
    }

    protected function releaseExpiredCooldowns(): void
    {
        MirrorToken::where('status', 'cooldown')
            ->where('cooldown_until', '<=', Carbon::now())
            ->update([
                'status' => 'active',
                'cooldown_until' => null,
            ]);
    }

    /**
     * Register all comma-separated MIRROR_SESSION_TOKENS from config if not already present,
     * keyed uniquely by SHA-256 hash of the session token.
     */
    public function syncStaticEnvTokens(): void
    {
        $staticTokens = config('mirror.pool.static_tokens', []);
        if (!is_array($staticTokens) || $staticTokens === []) {
            return;
        }

        foreach (array_values($staticTokens) as $idx => $rawToken) {
            $trimmed = trim((string) $rawToken);
            if ($trimmed === '') {
                continue;
            }
            $this->createOrGetStaticToken($trimmed, $idx + 1);
        }
    }

    protected function createOrGetStaticToken(string $rawToken, int $index = 1): MirrorToken
    {
        $hash = hash('sha256', trim($rawToken));
        $existing = MirrorToken::where('token_hash', $hash)->first();
        if ($existing) {
            return $existing;
        }

        // Preserve backward compatibility if an old single-token row exists without token_hash.
        if ($index === 1) {
            $legacy = MirrorToken::where('name', 'Env Fallback Token')->first();
            if ($legacy) {
                if (empty($legacy->token_hash)) {
                    $legacy->update(['token_hash' => $hash]);
                }
                return $legacy;
            }
        }

        return MirrorToken::create([
            'name' => $index === 1 ? 'Env Fallback Token' : "Env Fallback Token #{$index}",
            'session_token' => $rawToken,
            'token_hash' => $hash,
            'status' => 'active',
        ]);
    }

    public function getPoolStats(): array
    {
        $this->releaseExpiredCooldowns();

        $all = MirrorToken::all();

        return [
            'total_tokens' => $all->count(),
            'active_tokens' => $all->where('status', 'active')->count(),
            'cooldown_tokens' => $all->where('status', 'cooldown')->count(),
            'expired_tokens' => $all->where('status', 'expired')->count(),
            'disabled_tokens' => $all->where('status', 'disabled')->count(),
            'total_active_requests' => $all->sum('active_requests'),
            'total_lifetime_requests' => $all->sum('total_requests'),
            'total_tokens_used' => $all->sum('total_tokens_used'),
            'tokens' => $all->map(fn (MirrorToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'email' => $t->email,
                'account_id' => $t->account_id,
                'plan_type' => $t->plan_type,
                'status' => $t->status,
                'active_requests' => $t->active_requests,
                'total_requests' => $t->total_requests,
                'consecutive_errors' => $t->consecutive_errors,
                'cooldown_until' => $t->cooldown_until?->toIso8601String(),
                'expires_at' => $t->access_token_expires_at?->toIso8601String(),
                'has_turnstile_token' => $t->getTurnstileToken() !== null,
                'last_error' => $t->last_error,
            ])->values()->all(),
        ];
    }
}
