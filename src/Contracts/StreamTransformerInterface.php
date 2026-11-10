<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Contracts;

interface StreamTransformerInterface
{
    /**
     * Process an upstream SSE line from ChatGPT and convert it to OpenAI format chunk.
     *
     * @param string $sseLine
     * @param string $model
     * @param string $chatId
     * @return string|null Formatted SSE chunk (e.g. "data: {...}\n\n") or null if line skipped.
     */
    public function transformLine(string $sseLine, string $model, string $chatId): ?string;

    /**
     * Generate the terminal SSE chunk.
     */
    public function terminalChunk(string $model, string $chatId): string;
}
