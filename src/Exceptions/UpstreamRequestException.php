<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Exceptions;

class UpstreamRequestException extends MirrorException
{
    public function __construct(
        string $message,
        int $statusCode = 502,
        protected ?array $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromStatus(int $status, string $rawBody = ''): self
    {
        $decoded = json_decode($rawBody, true);
        $body = is_array($decoded) ? $decoded : null;

        $detail = '';
        if (is_array($body)) {
            $detail = (string) (
                $body['detail']['message']
                ?? (is_string($body['detail'] ?? null) ? $body['detail'] : '')
                ?: ($body['error']['message'] ?? $body['message'] ?? '')
            );
        }

        if ($detail === '' && trim($rawBody) !== '') {
            $detail = substr(preg_replace('/\s+/', ' ', trim($rawBody)) ?: '', 0, 280);
        }

        $msg = $detail !== ''
            ? "Upstream HTTP {$status}: {$detail}"
            : "Upstream HTTP {$status}";

        return new self($msg, $status, $body);
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
