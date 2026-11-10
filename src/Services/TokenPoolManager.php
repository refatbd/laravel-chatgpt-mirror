<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Refatbd\LaravelChatGptMirror\Contracts\TokenPoolInterface;
use Refatbd\LaravelChatGptMirror\Exceptions\TokenPoolExhaustedException;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

class TokenPoolManager implements TokenPoolInterface
{
    public function __construct(
        protected TokenRefresher $refresher
    ) {
    }

    /**
     * Acquire the best available token based on configured load balancing strategy.
     *
     * @throws TokenPoolExhaustedException
     */
    public function acquire(?string $preferredTokenId = null): ?MirrorToken
    {
        // 1. If preferredTokenId requested (sticky conversation routing)
        if ($preferredTokenId) {
            $preferred = MirrorToken::find($preferredTokenId);
            if ($preferred && $preferred->isHealthy()) {
                $this->ensureTokenFreshness($preferred);
                $preferred->incrementActive();
                return $preferred;
            }
        }

        // 2. Query all healthy tokens from storage
        $strategy = config('mirror.pool.strategy', 'least_busy');
        $query = MirrorToken::query()
            ->where('status', '!=', 'disabled')
            ->where('status', '!=', 'expired')
            ->where(function ($q) {
                $q->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', Carbon::now());
            });

        $tokens = $query->get();

        // 3. Fallback to static tokens in config/env if table is empty
        if ($tokens->isEmpty()) {
            $staticTokens = config('mirror.pool.static_tokens', []);
            if (!empty($staticTokens)) {
                $fallback = $this->createOrGetStaticToken($staticTokens[0]);
                if ($fallback && $fallback->isHealthy()) {
                    $this->ensureTokenFreshness($fallback);
                    $fallback->incrementActive();
                    return $fallback;
                }
            }
            throw new TokenPoolExhaustedException();
        }

        // 4. Select token according to strategy
        $selected = match ($strategy) {
            'round_robin' => $this->selectRoundRobin($tokens),
            'random' => $tokens->random(),
            default => $this->selectLeastBusy($tokens),
        };

        if (!$selected) {
            throw new TokenPoolExhaustedException();
        }

        // Ensure token has a valid access token before handing it over
        $this->ensureTokenFreshness($selected);

        // Atomically increment in-flight requests count
        $selected->incrementActive();

        return $selected;
    }

    /**
     * Least-busy strategy: Prioritizes tokens handling fewest concurrent requests.
     */
    protected function selectLeastBusy($tokens): ?MirrorToken
    {
        return $tokens->sortBy([
            ['active_requests', 'asc'],
            ['consecutive_errors', 'asc'],
            ['last_used_at', 'asc'],
        ])->first();
    }

    /**
     * Round-robin strategy: Cycles tokens evenly across requests.
     */
    protected function selectRoundRobin($tokens): ?MirrorToken
    {
        $count = $tokens->count();
        if ($count === 0) {
            return null;
        }

        $counterKey = 'mirror:round_robin_counter';
        $index = Cache::increment($counterKey) % $count;

        return $tokens->values()->get($index);
    }

    /**
     * Release token and decrement active counter.
     */
    public function release(MirrorToken $token, bool $success = true, ?int $tokensUsed = null): void
    {
        $token->decrementActive($success, $tokensUsed);
    }

    /**
     * Place token into cooldown after 429 Rate Limit.
     */
    public function putInCooldown(MirrorToken $token, ?int $seconds = null): void
    {
        $cooldownSeconds = $seconds ?? (int) config('mirror.pool.cooldown_seconds', 60);
        $token->enterCooldown($cooldownSeconds);
    }

    /**
     * Ensure the access token is populated and not expired.
     */
    public function ensureTokenFreshness(MirrorToken $token): void
    {
        if ($token->needsRefresh()) {
            $this->refresher->refresh($token);
        }
    }

    /**
     * Create or retrieve a token record from a raw static string in config.
     */
    protected function createOrGetStaticToken(string $rawToken): MirrorToken
    {
        return MirrorToken::firstOrCreate(
            ['name' => 'Env Fallback Token'],
            ['session_token' => $rawToken, 'status' => 'active']
        );
    }

    /**
     * Get real-time health stats of the pool.
     */
    public function getPoolStats(): array
    {
        $all = MirrorToken::all();

        return [
            'total_tokens' => $all->count(),
            'active_healthy' => $all->filter->isHealthy()->count(),
            'in_cooldown' => $all->where('status', 'cooldown')->count(),
            'total_in_flight_requests' => $all->sum('active_requests'),
            'total_requests_served' => $all->sum('total_requests'),
        ];
    }
}
