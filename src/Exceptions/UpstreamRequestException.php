<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Exceptions;

class UpstreamRequestException extends MirrorException
{
    public function __construct(
        string $message,
        int $statusCode = 502,
        protected ?array $responseBody = null
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
