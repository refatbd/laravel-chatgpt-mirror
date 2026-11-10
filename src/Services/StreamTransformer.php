<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Refatbd\LaravelChatGptMirror\Contracts\StreamTransformerInterface;

class StreamTransformer implements StreamTransformerInterface
{
    protected int $previousLength = 0;
    protected ?string $messageId = null;
    protected ?string $conversationId = null;
    protected bool $finished = false;

    /**
     * Transform incoming raw SSE line to OpenAI-compatible chat.completion.chunk.
     */
    public function transformLine(string $sseLine, string $model, string $chatId): ?string
    {
        $line = trim($sseLine);

        if (empty($line) || str_starts_with($line, ':') || str_starts_with($line, 'event:')) {
            return null;
        }

        if ($line === 'data: [DONE]') {
            return $this->terminalChunk($model, $chatId);
        }

        if (!str_starts_with($line, 'data: ')) {
            return null;
        }

        $jsonStr = substr($line, 6);
        $data = json_decode($jsonStr, true);

        if (!is_array($data) || !isset($data['message'])) {
            return null;
        }

        $message = $data['message'];
        $this->messageId = $message['id'] ?? $this->messageId ?? $chatId;
        $this->conversationId = $data['conversation_id'] ?? $this->conversationId;

        // Check author role
        $role = $message['author']['role'] ?? 'assistant';
        if ($role !== 'assistant') {
            return null;
        }

        // Extract textual content parts
        $parts = $message['content']['parts'] ?? [];
        if (empty($parts) || !is_string($parts[0])) {
            return null;
        }

        $fullText = $parts[0];
        $currentLength = mb_strlen($fullText, 'UTF-8');

        if ($currentLength <= $this->previousLength) {
            return null;
        }

        // Calculate newly arrived text chunk (delta)
        $delta = mb_substr($fullText, $this->previousLength, null, 'UTF-8');
        $this->previousLength = $currentLength;

        $openAiChunk = [
            'id' => 'chatcmpl-' . ($this->messageId ?: $chatId),
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'system_fingerprint' => 'fp_mirror_refatbd',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => [
                        'content' => $delta,
                    ],
                    'logprobs' => null,
                    'finish_reason' => null,
                ],
            ],
        ];

        return "data: " . json_encode($openAiChunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /**
     * Send terminal chunks (finish_reason: "stop" and [DONE]).
     */
    public function terminalChunk(string $model, string $chatId): string
    {
        if ($this->finished) {
            return '';
        }

        $this->finished = true;

        $stopChunk = [
            'id' => 'chatcmpl-' . ($this->messageId ?: $chatId),
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'system_fingerprint' => 'fp_mirror_refatbd',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => new \stdClass(),
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        return "data: " . json_encode($stopChunk, JSON_UNESCAPED_SLASHES) . "\n\ndata: [DONE]\n\n";
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getFullLength(): int
    {
        return $this->previousLength;
    }
}
