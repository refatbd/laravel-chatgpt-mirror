<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Events;

/**
 * Dispatched whenever an OpenAI-compatible request completes successfully.
 * Ideal for SaaS per-tenant usage metering, credit deduction, and analytics.
 */
class MirrorCompletionCompleted
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $endpoint,
        public readonly string $model,
        public readonly bool $stream,
        public readonly ?string $conversationId,
        public readonly ?int $tokenId,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        public readonly float $durationMs,
        public readonly mixed $authContext = null
    ) {}
}
