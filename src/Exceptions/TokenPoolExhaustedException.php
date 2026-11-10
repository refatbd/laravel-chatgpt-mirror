<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Exceptions;

class TokenPoolExhaustedException extends MirrorException
{
    public function __construct(string $message = 'All ChatGPT session tokens in the pool are currently exhausted or in cooldown.')
    {
        parent::__construct($message, 429);
    }
}
