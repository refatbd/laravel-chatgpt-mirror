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
        protected BrowserPatchManager $patchManager
    ) {
        $this->http = new Client([
            'base_uri' => config('mirror.upstream', 'https://chatgpt.com'),
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => (bool) config('mirror.egress.verify_ssl', true),
            'proxy' => config('mirror.egress.proxy'),
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
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
        $targetPath = '/' . ltrim($path ?? '', '/');
        $query = $request->getQueryString();
        if ($query) {
            $targetPath .= '?' . $query;
        }

        try {
            $response = $this->http->get($targetPath, [
                'http_errors' => false,
            ]);

            $contentType = $response->getHeaderLine('Content-Type');
            $body = (string) $response->getBody();

            $laravelResponse = response($body, $response->getStatusCode());

            // Forward filtered headers
            $headers = $this->patchManager->cleanHeaders($response->getHeaders());
            foreach ($headers as $key => $values) {
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
