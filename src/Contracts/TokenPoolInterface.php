<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Contracts;

use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

interface TokenPoolInterface
{
    /**
     * Acquire a healthy token according to the load balancing strategy (e.g. least_busy).
     */
    public function acquire(?string $preferredTokenId = null): ?MirrorToken;

    /**
     * Mark a token as having completed a request, decrementing active request counter.
     */
    public function release(MirrorToken $token, bool $success = true, ?int $tokensUsed = null): void;

    /**
     * Place a token in temporary cooldown when it receives a 429 Too Many Requests.
     */
    public function putInCooldown(MirrorToken $token, ?int $seconds = null): void;

    /**
     * Get summary metrics of healthy, active, and cooldown tokens in the pool.
     */
    public function getPoolStats(): array;
}
