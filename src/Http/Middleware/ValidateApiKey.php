<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates OpenAI-compatible gateway endpoints (/v1/*).
 * Accepts valid Bearer API key (or ?api_key=) or HMAC-signed HttpOnly browser control cookie.
 * Never trusts spoofable Referer headers.
 */
class ValidateApiKey
{
    public function __construct(
        protected SecurityService $security
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->security->authorizePublicApi($request)) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid or missing API key in Authorization header.',
                    'type' => 'invalid_request_error',
                    'param' => null,
                    'code' => 'invalid_api_key',
                ],
            ], 401);
        }

        return $next($request);
    }
}
