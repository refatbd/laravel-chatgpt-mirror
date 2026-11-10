<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror;

use Refatbd\LaravelChatGptMirror\Services\ChatGptClient;
use Refatbd\LaravelChatGptMirror\Services\TokenPoolManager;
use Refatbd\LaravelChatGptMirror\Services\TokenRefresher;

class Mirror
{
    public function __construct(
        protected TokenPoolManager $poolManager,
        protected ChatGptClient $client,
        protected TokenRefresher $refresher
    ) {
    }

    public function pool(): TokenPoolManager
    {
        return $this->poolManager;
    }

    public function client(): ChatGptClient
    {
        return $this->client;
    }

    public function refresher(): TokenRefresher
    {
        return $this->refresher;
    }

    /**
     * Helper to perform a programmatic chat completion.
     */
    public function chat(array $messages, string $model = 'gpt-4o', bool $stream = false)
    {
        $token = $this->poolManager->acquire();
        $payload = $this->client->buildConversationPayload($messages, $model);

        try {
            $collected = '';
            $this->client->streamConversation($payload, $token->access_token, function ($line) use (&$collected) {
                if (str_starts_with($line, 'data: ') && $line !== 'data: [DONE]') {
                    $json = json_decode(substr($line, 6), true);
                    if (isset($json['message']['content']['parts'][0])) {
                        $collected = $json['message']['content']['parts'][0];
                    }
                }
            });
            $this->poolManager->release($token, true, mb_strlen($collected));
            return $collected;
        } catch (\Throwable $e) {
            $this->poolManager->release($token, false);
            throw $e;
        }
    }
}
