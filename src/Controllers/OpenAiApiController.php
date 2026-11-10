<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Exceptions\TokenPoolExhaustedException;
use Refatbd\LaravelChatGptMirror\Exceptions\UpstreamRequestException;
use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\StreamTransformer;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OpenAiApiController extends Controller
{
    public function __construct(
        protected TokenPoolManager $poolManager,
        protected ChatGptClient $client
    ) {
    }

    /**
     * List available models.
     */
    public function models(Request $request): JsonResponse
    {
        $token = null;
        try {
            $token = $this->poolManager->acquire();
        } catch (Throwable) {
            // It's acceptable if no token is available, getModels will return static fallback list
        }

        $accessToken = $token ? $token->access_token : null;
        $models = $this->client->getModels($accessToken);

        if ($token) {
            $this->poolManager->release($token, true);
        }

        return response()->json($models);
    }

    /**
     * Handle chat completion requests with high-concurrency support.
     */
    public function chatCompletions(Request $request)
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string',
            'messages.*.content' => 'required',
            'model' => 'nullable|string',
            'stream' => 'nullable|boolean',
        ]);

        $stream = (bool) ($validated['stream'] ?? false);
        $model = (string) ($validated['model'] ?? 'gpt-4o');
        $chatId = 'chatcmpl-' . Str::random(24);

        // Acquire a healthy token from the pool (least-busy algorithm)
        $token = $this->poolManager->acquire();

        if (!$token || empty($token->access_token)) {
            throw new TokenPoolExhaustedException();
        }

        $payload = $this->client->buildConversationPayload($validated['messages'], $model);

        if ($stream) {
            return $this->handleStreamResponse($payload, $token, $model, $chatId);
        }

        return $this->handleSyncResponse($payload, $token, $model, $chatId);
    }

    /**
     * Handle streaming Server-Sent Events (SSE) response.
     */
    protected function handleStreamResponse(array $payload, $token, string $model, string $chatId): StreamedResponse
    {
        $poolManager = $this->poolManager;
        $client = $this->client;
        $accessToken = $token->access_token;

        return response()->stream(function () use ($payload, $token, $model, $chatId, $poolManager, $client, $accessToken) {
            // Terminate any active output buffering for true real-time SSE chunks
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $transformer = new StreamTransformer();
            $success = true;

            try {
                $client->streamConversation($payload, $accessToken, function (string $rawLine) use ($transformer, $model, $chatId) {
                    $chunk = $transformer->transformLine($rawLine, $model, $chatId);
                    if ($chunk !== null) {
                        echo $chunk;
                        if (function_exists('fastcgi_finish_request')) {
                            // In PHP-FPM flush buffer immediately
                            flush();
                        } else {
                            flush();
                        }
                    }
                });

                // Send termination chunk if not sent
                echo $transformer->terminalChunk($model, $chatId);
                flush();
            } catch (UpstreamRequestException $e) {
                $success = false;
                if ($e->getCode() === 429) {
                    $poolManager->putInCooldown($token);
                }

                $errorPayload = [
                    'error' => [
                        'message' => $e->getMessage(),
                        'type' => 'upstream_error',
                        'code' => $e->getCode(),
                    ],
                ];
                echo "data: " . json_encode($errorPayload) . "\n\n";
                flush();
            } catch (Throwable $e) {
                $success = false;
                $errorPayload = [
                    'error' => [
                        'message' => 'Internal streaming error: ' . $e->getMessage(),
                        'type' => 'server_error',
                        'code' => 500,
                    ],
                ];
                echo "data: " . json_encode($errorPayload) . "\n\n";
                flush();
            } finally {
                $poolManager->release($token, $success, $transformer->getFullLength());
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Critical for Nginx non-buffering
        ]);
    }

    /**
     * Handle non-streaming completion response.
     */
    protected function handleSyncResponse(array $payload, $token, string $model, string $chatId): JsonResponse
    {
        $collectedText = '';
        $success = true;

        try {
            $this->client->streamConversation($payload, $token->access_token, function (string $rawLine) use (&$collectedText) {
                $line = trim($rawLine);
                if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                    $data = json_decode(substr($line, 6), true);
                    if (isset($data['message']['content']['parts'][0])) {
                        $collectedText = $data['message']['content']['parts'][0];
                    }
                }
            });

            return response()->json([
                'id' => $chatId,
                'object' => 'chat.completion',
                'created' => time(),
                'model' => $model,
                'system_fingerprint' => 'fp_mirror_refatbd',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $collectedText,
                        ],
                        'logprobs' => null,
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => mb_strlen($collectedText),
                    'total_tokens' => mb_strlen($collectedText),
                ],
            ]);
        } catch (UpstreamRequestException $e) {
            $success = false;
            if ($e->getCode() === 429) {
                $this->poolManager->putInCooldown($token);
            }
            return response()->json([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'upstream_error',
                    'code' => $e->getCode(),
                ],
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 502);
        } catch (Throwable $e) {
            $success = false;
            return response()->json([
                'error' => [
                    'message' => 'Internal server error: ' . $e->getMessage(),
                    'type' => 'server_error',
                    'code' => 500,
                ],
            ], 500);
        } finally {
            $this->poolManager->release($token, $success, mb_strlen($collectedText));
        }
    }
}
