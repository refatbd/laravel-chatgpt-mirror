<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects internal Mirror control, session, conversation, upload, and admin mutation routes.
 */
class ProtectControlEndpoints
{
    public function __construct(
        protected SecurityService $security
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->security->authorizeControlEndpoint($request)) {
            return response()->json([
                'error' => 'Unauthorized: provide a valid Bearer API key or load a Mirror browser page.',
            ], 401);
        }

        return $next($request);
    }
}
