<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = config('mirror.api_key');

        if (empty($configuredKey)) {
            return $next($request);
        }

        $authHeader = $request->header('Authorization', '');
        $token = str_starts_with($authHeader, 'Bearer ')
            ? substr($authHeader, 7)
            : $request->query('api_key', '');

        if (!hash_equals((string) $configuredKey, (string) $token)) {
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
