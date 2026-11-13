<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers\Concerns;

use Illuminate\Http\Request;
use Refatbd\LaravelChatGptMirror\Mirror;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

/**
 * Shared proxy context resolver used by BrowserProxyController and BackendApiController
 * so local token pools and remote mirror nodes follow one identical lifecycle.
 */
trait ResolvesWebProxyContext
{
    /**
     * @return array{
     *     resolved_by_hook: bool,
     *     short_circuit: ?\Symfony\Component\HttpFoundation\Response,
     *     token: ?MirrorToken,
     *     upstream_url: ?string,
     *     upstream_headers: array<string, string>,
     *     http_proxy: ?string,
     *     skip_token_pool: bool,
     *     relay_cdn: bool,
     *     base_path: string,
     *     session_user: ?array<string, mixed>,
     *     session_account: ?array<string, mixed>,
     *     release: ?callable,
     *     managed_externally: bool,
     *     meta: array<string, mixed>
     * }
     */
    protected function resolveWebProxyContext(Request $request): array
    {
        $resolver = Mirror::resolveWebContextResolver();
        if ($resolver !== null) {
            $custom = $resolver($request);
            if ($custom instanceof \Symfony\Component\HttpFoundation\Response) {
                return $this->emptyWebContext(true, $custom);
            }

            if (is_array($custom)) {
                if (($custom['response'] ?? null) instanceof \Symfony\Component\HttpFoundation\Response) {
                    return $this->emptyWebContext(true, $custom['response']);
                }

                $upstreamUrl = isset($custom['upstream_url']) && is_string($custom['upstream_url'])
                    ? rtrim(trim($custom['upstream_url']), '/')
                    : '';
                $upstreamUrl = $upstreamUrl !== '' ? $upstreamUrl : null;

                $rawBasePath = isset($custom['base_path']) && is_string($custom['base_path'])
                    ? '/' . trim($custom['base_path'], '/')
                    : '';
                if ($rawBasePath === '/') {
                    $rawBasePath = '';
                }

                $headers = [];
                if (isset($custom['upstream_headers']) && is_array($custom['upstream_headers'])) {
                    foreach ($custom['upstream_headers'] as $k => $v) {
                        if (is_string($k) && $k !== '' && (is_string($v) || is_numeric($v))) {
                            $headers[$k] = (string) $v;
                        }
                    }
                }

                $token = ($custom['token'] ?? null) instanceof MirrorToken ? $custom['token'] : null;

                return [
                    'resolved_by_hook' => true,
                    'short_circuit' => null,
                    'token' => $token,
                    'upstream_url' => $upstreamUrl,
                    'upstream_headers' => $headers,
                    'http_proxy' => isset($custom['http_proxy']) && is_string($custom['http_proxy']) && trim($custom['http_proxy']) !== ''
                        ? trim($custom['http_proxy'])
                        : null,
                    'skip_token_pool' => (bool) ($custom['skip_token_pool'] ?? ($upstreamUrl !== null && $token === null)),
                    'relay_cdn' => (bool) ($custom['relay_cdn'] ?? false),
                    'base_path' => $rawBasePath,
                    'session_user' => isset($custom['session_user']) && is_array($custom['session_user']) ? $custom['session_user'] : null,
                    'session_account' => isset($custom['session_account']) && is_array($custom['session_account']) ? $custom['session_account'] : null,
                    'release' => is_callable($custom['release'] ?? null) ? $custom['release'] : null,
                    'managed_externally' => (bool) ($custom['managed_externally'] ?? false),
                    'meta' => isset($custom['meta']) && is_array($custom['meta']) ? $custom['meta'] : [],
                ];
            }
        }

        // Default v2.1.0 standalone behavior when no SaaS resolver overrides the request.
        $token = $this->pool->acquire();

        return [
            'resolved_by_hook' => false,
            'short_circuit' => null,
            'token' => $token,
            'upstream_url' => null,
            'upstream_headers' => [],
            'http_proxy' => null,
            'skip_token_pool' => false,
            'relay_cdn' => false,
            'base_path' => '',
            'session_user' => null,
            'session_account' => null,
            'release' => null,
            'managed_externally' => false,
            'meta' => [],
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    protected function releaseWebProxyContext(array $ctx, bool $success): void
    {
        if (is_callable($ctx['release'] ?? null)) {
            ($ctx['release'])($success);
            return;
        }

        if (($ctx['token'] ?? null) instanceof MirrorToken && empty($ctx['managed_externally'])) {
            $this->pool->release($ctx['token'], $success);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyWebContext(bool $resolvedByHook, ?\Symfony\Component\HttpFoundation\Response $response = null): array
    {
        return [
            'resolved_by_hook' => $resolvedByHook,
            'short_circuit' => $response,
            'token' => null,
            'upstream_url' => null,
            'upstream_headers' => [],
            'http_proxy' => null,
            'skip_token_pool' => true,
            'relay_cdn' => false,
            'base_path' => '',
            'session_user' => null,
            'session_account' => null,
            'release' => null,
            'managed_externally' => true,
            'meta' => [],
        ];
    }
}
