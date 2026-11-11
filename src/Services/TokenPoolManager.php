<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Refatbd\LaravelChatGptMirror\Contracts\TokenPoolInterface;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

class TokenPoolManager implements TokenPoolInterface
{
    public function __construct(
        protected TokenRefresher $refresher,
        protected ?MirrorCache $cache = null
    ) {
        $this->cache ??= $this->resolveCache();
    }

    protected function cache(): MirrorCache
    {
        return $this->cache ??= $this->resolveCache();
    }

    protected function resolveCache(): MirrorCache
    {
        if (function_exists('app') && app()->bound(MirrorCache::class)) {
            return app(MirrorCache::class);
        }

        return new MirrorCache();
    }

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

        $maxConcurrent = max(1, (int) config('mirror.pool.max_concurrent_per_token', 5));
        $strategy = (string) config('mirror.pool.strategy', 'least_busy');

        if ($preferredTokenId !== null && $preferredTokenId !== '') {
            $preferred = MirrorToken::find($preferredTokenId);
            if ($preferred && $preferred->isHealthy() && $preferred->active_requests < $maxConcurrent) {
                if ($this->ensureTokenFreshness($preferred) && $preferred->tryAcquireSlot($maxConcurrent)) {
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

        $ordered = $this->orderCandidates($tokens, $strategy);

        foreach ($ordered as $candidate) {
            if (!$this->ensureTokenFreshness($candidate)) {
                continue;
            }

            if ($candidate->tryAcquireSlot($maxConcurrent)) {
                return $candidate;
            }
        }

        return null;
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

    /**
     * Order healthy candidates according to the configured load-balancing strategy.
     * Returning all candidates in priority order lets acquireForAccount() fail over
     * cleanly if a token fails refresh or loses an atomic concurrency-slot race.
     */
    protected function orderCandidates(Collection $tokens, string $strategy): Collection
    {
        if ($strategy !== 'round_robin') {
            return $tokens->sortBy([
                ['active_requests', 'asc'],
                ['consecutive_errors', 'asc'],
                ['last_used_at', 'asc'],
            ])->values();
        }

        $sorted = $tokens->sortBy('id')->values();
        $count = $sorted->count();
        if ($count <= 1) {
            return $sorted;
        }

        $seq = max(1, $this->cache()->increment('pool:rr_index', 1, 86400));
        $startIndex = ($seq - 1) % $count;

        $rotated = new Collection();
        for ($i = 0; $i < $count; $i++) {
            $rotated->push($sorted->get(($startIndex + $i) % $count));
        }

        return $rotated;
    }

    protected function selectRoundRobin(Collection $tokens): ?MirrorToken
    {
        return $this->orderCandidates($tokens, 'round_robin')->first();
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

        $lockTtl = max(5, (int) config('mirror.cache.lock_ttl', 15));
        $lockWait = max(1, (int) config('mirror.cache.lock_wait', 5));

        return (bool) $this->cache()->lock("token_refresh:{$token->id}", $lockTtl, function () use ($token): bool {
            $fresh = MirrorToken::find($token->id);
            if ($fresh) {
                $token->setRawAttributes($fresh->getAttributes(), true);
                if (!$token->needsRefresh()) {
                    return true;
                }
            }

            $ok = $this->refresher->refresh($token);
            if ($ok) {
                $this->invalidateDiscoveryCaches($token->getEffectiveAccountId());
            }

            return $ok;
        }, $lockWait);
    }

    public function invalidateDiscoveryCaches(?string $accountId = null): void
    {
        $keys = ['models:default', 'gpts:default', 'pool:primary_account'];
        if ($accountId !== null && $accountId !== '') {
            $keys[] = "models:{$accountId}";
            $keys[] = "gpts:{$accountId}";
        }

        foreach (MirrorToken::query()->pluck('account_id')->filter()->unique() as $aid) {
            $keys[] = "models:{$aid}";
            $keys[] = "gpts:{$aid}";
        }

        foreach (array_unique($keys) as $key) {
            $this->cache()->forget($key);
        }
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

        return $this->cache()->lock("sync_static:{$hash}", 10, function () use ($rawToken, $index, $hash): MirrorToken {
            $rechecked = MirrorToken::where('token_hash', $hash)->first();
            if ($rechecked) {
                return $rechecked;
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
        }, 5);
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
