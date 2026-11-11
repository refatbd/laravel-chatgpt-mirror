<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Events;

/**
 * Dispatched whenever an OpenAI-compatible request fails or is rejected upstream.
 */
class MirrorCompletionFailed
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $endpoint,
        public readonly string $model,
        public readonly bool $stream,
        public readonly int $statusCode,
        public readonly string $category,
        public readonly string $errorMessage,
        public readonly float $durationMs,
        public readonly mixed $authContext = null
    ) {}
}
