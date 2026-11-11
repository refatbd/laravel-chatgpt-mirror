<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Refatbd\LaravelChatGptMirror\Services\SecurityService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces optional SaaS authorization callback (`Mirror::authorizeAdminUsing`) on
 * Admin Dashboard and runtime configuration endpoints while remaining permissive when unset.
 */
class AuthorizeAdmin
{
    public function __construct(
        protected SecurityService $security
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->security->authorizeAdmin($request)) {
            if ($request->expectsJson() || str_starts_with('/' . ltrim($request->path(), '/'), '/api/')) {
                return response()->json([
                    'error' => [
                        'message' => 'Forbidden: Mirror admin authorization denied.',
                        'type' => 'invalid_request_error',
                        'param' => null,
                        'code' => 'forbidden',
                    ],
                ], 403);
            }

            return response('Forbidden: Mirror admin authorization denied.', 403);
        }

        return $next($request);
    }
}
