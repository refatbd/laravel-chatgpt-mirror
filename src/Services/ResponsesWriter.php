<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Illuminate\Support\Str;

/**
 * Formats synchronous JSON responses and streaming SSE event frames
 * conforming to OpenAI's `/v1/responses` API specification.
 */
class ResponsesWriter
{
    protected string $responseId;
    protected string $itemId;
    protected int $createdAt;
    protected string $requestedModel;
    protected int $sequenceNumber = 0;

    /** @var array<int, array{messageId: ?string, text: string}> */
    protected array $summaries = [];

    public function __construct(string $requestedModel)
    {
        $this->responseId = 'resp_' . str_replace('-', '', (string) Str::uuid());
        $this->itemId = 'msg_' . str_replace('-', '', (string) Str::uuid());
        $this->createdAt = time();
        $this->requestedModel = $requestedModel;
    }

    /**
     * @param array<int, array{messageId: ?string, text: string}> $summaries
     */
    public function setSummaries(array $summaries): void
    {
        $this->summaries = $summaries;
    }

    /**
     * Normalize `/v1/responses` request body (`input`, `instructions`, `model`, `stream`, `metadata`)
     * into an OpenAI-style `messages` list cleanly.
     *
     * @param array<string, mixed> $body
     * @return array<int, array<string, mixed>>
     */
    public function normalizeInputToMessages(array $body): array
    {
        $messages = [];

        if (!empty($body['instructions']) && is_string($body['instructions'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $body['instructions'],
            ];
        }

        $input = $body['input'] ?? '';

        if (is_string($input)) {
            if ($input !== '') {
                $messages[] = ['role' => 'user', 'content' => $input];
            }
            return $messages;
        }

        if (is_array($input)) {
            foreach ($input as $entry) {
                if (is_string($entry)) {
                    $messages[] = ['role' => 'user', 'content' => $entry];
                    continue;
                }
                if (!is_array($entry)) {
                    continue;
                }
                $role = (string) ($entry['role'] ?? 'user');
                $content = $entry['content'] ?? '';

                if (is_array($content)) {
                    $normalizedParts = [];
                    foreach ($content as $part) {
                        if (!is_array($part)) {
                            continue;
                        }
                        $type = (string) ($part['type'] ?? 'input_text');
                        if ($type === 'input_text' || $type === 'text' || $type === 'output_text') {
                            $normalizedParts[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
                        } elseif ($type === 'input_image' && !empty($part['image_url'])) {
                            $url = is_array($part['image_url']) ? ($part['image_url']['url'] ?? '') : $part['image_url'];
                            if ($url !== '') {
                                $normalizedParts[] = ['type' => 'image_url', 'image_url' => ['url' => (string) $url]];
                            }
                        } elseif ($type === 'image_url') {
                            $normalizedParts[] = $part;
                        } elseif ($type === 'input_file' || $type === 'file') {
                            $normalizedParts[] = $part;
                        }
                    }
                    $messages[] = ['role' => $role, 'content' => $normalizedParts];
                } else {
                    $messages[] = ['role' => $role, 'content' => (string) $content];
                }
            }
        }

        return $messages;
    }

    /**
     * Build opening SSE events before deltas arrive.
     */
    public function openStreamEvents(): string
    {
        $initial = $this->buildResponseObject('', 'in_progress', $this->requestedModel, []);
        $item = [
            'id' => $this->itemId,
            'type' => 'message',
            'status' => 'in_progress',
            'role' => 'assistant',
            'content' => [],
        ];
        $part = [
            'type' => 'output_text',
            'text' => '',
            'annotations' => [],
        ];

        return $this->sse('response.created', ['type' => 'response.created', 'sequence_number' => $this->nextSeq(), 'response' => $initial])
            . $this->sse('response.in_progress', ['type' => 'response.in_progress', 'sequence_number' => $this->nextSeq(), 'response' => $initial])
            . $this->sse('response.output_item.added', ['type' => 'response.output_item.added', 'sequence_number' => $this->nextSeq(), 'output_index' => 0, 'item' => $item])
            . $this->sse('response.content_part.added', ['type' => 'response.content_part.added', 'sequence_number' => $this->nextSeq(), 'item_id' => $this->itemId, 'output_index' => 0, 'content_index' => 0, 'part' => $part]);
    }

    public function deltaEvent(string $delta): string
    {
        return $this->sse('response.output_text.delta', [
            'type' => 'response.output_text.delta',
            'sequence_number' => $this->nextSeq(),
            'item_id' => $this->itemId,
            'output_index' => 0,
            'content_index' => 0,
            'delta' => $delta,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function completeEvents(string $fullText, string $model, array $metadata = []): string
    {
        $finalPart = ['type' => 'output_text', 'text' => $fullText, 'annotations' => []];
        $finalItem = [
            'id' => $this->itemId,
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [$finalPart],
        ];
        $completed = $this->buildResponseObject($fullText, 'completed', $model, $metadata);

        return $this->sse('response.output_text.done', ['type' => 'response.output_text.done', 'sequence_number' => $this->nextSeq(), 'item_id' => $this->itemId, 'output_index' => 0, 'content_index' => 0, 'text' => $fullText])
            . $this->sse('response.content_part.done', ['type' => 'response.content_part.done', 'sequence_number' => $this->nextSeq(), 'item_id' => $this->itemId, 'output_index' => 0, 'content_index' => 0, 'part' => $finalPart])
            . $this->sse('response.output_item.done', ['type' => 'response.output_item.done', 'sequence_number' => $this->nextSeq(), 'output_index' => 0, 'item' => $finalItem])
            . $this->sse('response.completed', ['type' => 'response.completed', 'sequence_number' => $this->nextSeq(), 'response' => $completed]);
    }

    public function failEvents(string $message, string $code = 'upstream_error'): string
    {
        $failed = $this->buildResponseObject('', 'failed', $this->requestedModel, []);
        $failed['error'] = ['code' => $code, 'message' => $message];

        return $this->sse('response.failed', [
            'type' => 'response.failed',
            'sequence_number' => $this->nextSeq(),
            'response' => $failed,
        ]);
    }

    /**
     * Build complete synchronous Responses API JSON object.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function buildResponseObject(string $fullText, string $status, string $model, array $metadata = []): array
    {
        $output = [];

        foreach ($this->summaries as $idx => $summary) {
            if (trim($summary['text']) === '') {
                continue;
            }
            $output[] = [
                'id' => 'rs_' . substr($this->itemId, 4) . '_' . $idx,
                'type' => 'reasoning',
                'summary' => [
                    ['type' => 'summary_text', 'text' => $summary['text']],
                ],
                'status' => 'completed',
            ];
        }

        if ($status !== 'in_progress') {
            $output[] = [
                'id' => $this->itemId,
                'type' => 'message',
                'status' => $status,
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => $fullText,
                        'annotations' => [],
                    ],
                ],
            ];
        }

        $cleanMetadata = $metadata;
        unset($cleanMetadata['turnstile_token'], $cleanMetadata['mirror_turnstile_token']);

        return [
            'id' => $this->responseId,
            'object' => 'response',
            'created_at' => $this->createdAt,
            'status' => $status,
            'error' => null,
            'incomplete_details' => null,
            'model' => $model,
            'output' => $output,
            'output_text' => $fullText,
            'usage' => null,
            'metadata' => $cleanMetadata !== [] ? $cleanMetadata : new \stdClass(),
        ];
    }

    protected function nextSeq(): int
    {
        return ++$this->sequenceNumber;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function sse(string $event, array $payload): string
    {
        return "event: {$event}\ndata: " . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
