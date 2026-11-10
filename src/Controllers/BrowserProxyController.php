<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Refatbd\LaravelChatGptMirror\Services\BrowserPatchManager;
use Throwable;

class BrowserProxyController extends Controller
{
    protected Client $http;

    public function __construct(
        protected BrowserPatchManager $patchManager,
        protected \Refatbd\LaravelChatGptMirror\Services\TokenPoolManager $poolManager
    ) {
        $this->http = new Client([
            'base_uri' => config('mirror.upstream', 'https://chatgpt.com'),
            'timeout' => 45,
            'connect_timeout' => 10,
            'verify' => (bool) config('mirror.egress.verify_ssl', true),
            'proxy' => config('mirror.egress.proxy'),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]);
    }

    /**
     * Proxy ChatGPT Web UI root and sub-paths.
     */
    public function proxy(Request $request, ?string $path = null): Response
    {
        $resolvedPath = $path ?? $request->path();
        if ($resolvedPath === 'mirror' || $resolvedPath === '/') {
            $targetPath = '/';
        } else {
            $targetPath = '/' . ltrim(preg_replace('#^mirror/?#', '', $resolvedPath), '/');
        }

        $query = $request->getQueryString();
        if ($query) {
            $targetPath .= '?' . $query;
        }

        $headers = [
            'Origin' => 'https://chatgpt.com',
            'Referer' => 'https://chatgpt.com/',
        ];

        try {
            $token = $this->poolManager->acquire();
            if ($token && $token->session_token) {
                $headers['Cookie'] = "__Secure-next-auth.session-token={$token->session_token}";
            }
            if ($token) {
                $this->poolManager->release($token, true);
            }
        } catch (Throwable) {}

        try {
            $options = [
                'http_errors' => false,
                'headers' => $headers,
            ];

            if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch')) {
                $options['body'] = $request->getContent();
                $headers['Content-Type'] = $request->header('Content-Type', 'application/json');
            }

            $response = $this->http->request($request->method(), $targetPath, $options);

            $contentType = $response->getHeaderLine('Content-Type');
            $body = (string) $response->getBody();

            $laravelResponse = response($body, $response->getStatusCode());

            // Forward filtered headers
            $respHeaders = $this->patchManager->cleanHeaders($response->getHeaders());
            foreach ($respHeaders as $key => $values) {
                if (strtolower($key) !== 'transfer-encoding') {
                    $laravelResponse->header($key, implode(', ', $values));
                }
            }

            // Patch HTML if content is HTML
            if (stripos($contentType, 'text/html') !== false) {
                $patchedBody = $this->patchManager->patchHtml($body);
                $laravelResponse->setContent($patchedBody);
            }

            return $laravelResponse;
        } catch (GuzzleException $e) {
            return response("Upstream gateway error: " . $e->getMessage(), 502);
        } catch (Throwable $e) {
            return response("Proxy error: " . $e->getMessage(), 500);
        }
    }
}
