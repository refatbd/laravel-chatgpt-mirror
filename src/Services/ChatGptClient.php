<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Throwable;

class ChatGptClient
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => config('mirror.upstream', 'https://chatgpt.com'),
            'timeout' => (float) config('mirror.egress.timeout', 90),
            'connect_timeout' => (float) config('mirror.egress.connect_timeout', 10),
            'verify' => (bool) config('mirror.egress.verify_ssl', true),
            'proxy' => config('mirror.egress.proxy'),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Origin' => 'https://chatgpt.com',
                'Referer' => 'https://chatgpt.com/',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
            ],
        ]);
    }

    /**
     * Map common OpenAI model aliases to ChatGPT internal models.
     */
    public function mapModel(string $model): string
    {
        return match (strtolower($model)) {
            'gpt-4o-mini' => 'gpt-4o-mini',
            'gpt-4', 'gpt-4-turbo' => 'gpt-4',
            'o1', 'o1-preview' => 'o1',
            'o3-mini' => 'o3-mini',
            default => 'auto', // ChatGPT auto model selector
        };
    }

    /**
     * Transform OpenAI messages array to ChatGPT conversation payload.
     */
    public function buildConversationPayload(
        array $messages,
        string $model = 'auto',
        ?string $parentMessageId = null,
        ?string $conversationId = null
    ): array {
        $lastUserMessage = '';
        foreach (array_reverse($messages) as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $lastUserMessage = is_array($msg['content']) ? ($msg['content'][0]['text'] ?? '') : (string) $msg['content'];
                break;
            }
        }

        $messageId = (string) Str::uuid();
        $parentMessageId = $parentMessageId ?: (string) Str::uuid();

        $payload = [
            'action' => 'next',
            'messages' => [
                [
                    'id' => $messageId,
                    'author' => ['role' => 'user'],
                    'content' => [
                        'content_type' => 'text',
                        'parts' => [$lastUserMessage],
                    ],
                    'metadata' => new \stdClass(),
                ],
            ],
            'model' => $this->mapModel($model),
            'parent_message_id' => $parentMessageId,
            'timezone_offset_min' => -360,
            'suggestions' => [],
            'history_and_training_disabled' => false,
            'conversation_mode' => ['kind' => 'primary_assistant'],
            'force_paragen' => false,
            'force_paragen_model_slug' => '',
            'force_nulligen' => false,
            'force_rate_limit' => false,
            'reset_rate_limits' => false,
        ];

        if ($conversationId) {
            $payload['conversation_id'] = $conversationId;
        }

        return $payload;
    }

    /**
     * Stream conversation directly from upstream ChatGPT with low memory overhead.
     *
     * @param array $payload
     * @param string $accessToken
     * @param callable $onLine Callback receiving each raw SSE line
     * @throws UpstreamRequestException
     */
    public function streamConversation(array $payload, string $accessToken, callable $onLine): void
    {
        try {
            $response = $this->http->post('/backend-api/conversation', [
                RequestOptions::HEADERS => [
                    'Authorization' => "Bearer {$accessToken}",
                    'Accept' => 'text/event-stream',
                    'Content-Type' => 'application/json',
                ],
                RequestOptions::JSON => $payload,
                RequestOptions::STREAM => true,
            ]);

            $body = $response->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $chunk = $body->read(1024);
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $onLine($line);
                }
            }

            if (!empty($buffer)) {
                $onLine($buffer);
            }
        } catch (ClientException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 502;
            $responseBody = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;

            throw new UpstreamRequestException(
                "Upstream ChatGPT request failed [{$statusCode}]: " . $e->getMessage(),
                $statusCode,
                $responseBody
            );
        } catch (GuzzleException $e) {
            throw new UpstreamRequestException("Upstream connection error: " . $e->getMessage(), 502);
        } catch (Throwable $e) {
            throw new UpstreamRequestException("Unexpected streaming error: " . $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve supported models, cached to protect from high-frequency queries.
     */
    public function getModels(?string $accessToken = null): array
    {
        $cacheKey = 'mirror:available_models';
        $ttl = config('mirror.cache.models_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($accessToken) {
            $defaultModels = [
                ['id' => 'gpt-4o', 'object' => 'model', 'created' => 1715367049, 'owned_by' => 'system'],
                ['id' => 'gpt-4o-mini', 'object' => 'model', 'created' => 1721295804, 'owned_by' => 'system'],
                ['id' => 'o1', 'object' => 'model', 'created' => 1726169000, 'owned_by' => 'system'],
                ['id' => 'o3-mini', 'object' => 'model', 'created' => 1738300000, 'owned_by' => 'system'],
                ['id' => 'gpt-4', 'object' => 'model', 'created' => 1687882411, 'owned_by' => 'openai'],
            ];

            if (empty($accessToken)) {
                return ['object' => 'list', 'data' => $defaultModels];
            }

            try {
                $response = $this->http->get('/backend-api/models', [
                    RequestOptions::HEADERS => [
                        'Authorization' => "Bearer {$accessToken}",
                        'Accept' => 'application/json',
                    ],
                ]);

                $body = json_decode((string) $response->getBody(), true);
                $models = [];

                if (isset($body['models']) && is_array($body['models'])) {
                    foreach ($body['models'] as $m) {
                        $models[] = [
                            'id' => $m['slug'] ?? ($m['id'] ?? 'unknown'),
                            'object' => 'model',
                            'created' => time(),
                            'owned_by' => 'openai',
                        ];
                    }
                }

                return ['object' => 'list', 'data' => !empty($models) ? $models : $defaultModels];
            } catch (Throwable) {
                return ['object' => 'list', 'data' => $defaultModels];
            }
        });
    }
}
