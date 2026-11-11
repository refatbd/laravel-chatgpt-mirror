<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

/**
 * Strips restrictive proxy response headers and injects the early runtime transport
 * patch script into proxied HTML while keeping `/` completely clean visually.
 */
class BrowserPatchManager
{
    public function __construct(
        protected ?UrlRewriter $rewriter = null
    ) {
        $this->rewriter = $this->rewriter ?? new UrlRewriter();
    }

    /**
     * Strip headers that break reverse proxying, decompression, or script transport.
     * Preserves X-Frame-Options unless blocked by framing policy contract tests.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function cleanHeaders(array $headers): array
    {
        $strip = [
            'content-security-policy',
            'content-security-policy-report-only',
            'x-content-security-policy',
            'cross-origin-embedder-policy',
            'cross-origin-opener-policy',
            'cross-origin-resource-policy',
            'strict-transport-security',
            'alt-svc',
            'content-encoding',
            'content-length',
            'transfer-encoding',
            'connection',
            'keep-alive',
            'set-cookie',
            'public-key-pins',
            'x-webkit-csp',
        ];

        $cleaned = [];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower((string) $key), $strip, true)) {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Rewrite upstream origins and inject EARLY_PATCH before application JS runs.
     */
    public function patchHtml(string $html, ?string $localOrigin = null): string
    {
        if ($localOrigin !== null && $localOrigin !== '') {
            $html = $this->rewriter->rewriteText($html, $localOrigin, 'text/html');
        }

        $script = $this->buildEarlyPatchScript($localOrigin ?: '');

        if (preg_match('/<head[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1] + strlen($matches[0][0]);
            return substr($html, 0, $pos) . "\n" . $script . "\n" . substr($html, $pos);
        }

        return $script . "\n" . $html;
    }

    protected function buildEarlyPatchScript(string $localOrigin): string
    {
        $jsonOrigin = json_encode(rtrim($localOrigin, '/'), JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script data-mirror-patch="early">
(function(){
  try {
    var localOrigin = {$jsonOrigin} || window.location.origin;
    var localWs = localOrigin.replace(/^http/i, 'ws');
    function rewriteUrl(u) {
      if (typeof u !== 'string') return u;
      if (u.indexOf('/__codex-api/') !== -1) {
        u = u.replace(/\/__codex-api\//g, '/backend-api/');
      }
      u = u.replace(/^https:\/\/(?:chat\.openai\.com|chatgpt\.com)/i, localOrigin);
      u = u.replace(/^https:\/\/cdn\.oaistatic\.com/i, localOrigin + '/cdn');
      u = u.replace(/^wss:\/\/(?:ws\.)?chatgpt\.com/i, localWs);
      if (u.indexOf('https://ab.chatgpt.com') === 0 || u.indexOf('https://rum.browser-intake-datadoghq.com') === 0) {
        return localOrigin + '/ces/noop';
      }
      return u;
    }

    var origFetch = window.fetch;
    if (typeof origFetch === 'function') {
      var patchedFetch = function(input, init) {
        if (typeof input === 'string') {
          input = rewriteUrl(input);
        } else if (input && typeof input === 'object' && typeof input.url === 'string') {
          var nextUrl = rewriteUrl(input.url);
          if (nextUrl !== input.url && typeof Request !== 'undefined') {
            try {
              input = new Request(nextUrl, input);
            } catch (_e) {}
          }
        }
        return origFetch.call(this, input, init);
      };
      try {
        Object.defineProperty(window, 'fetch', {
          configurable: true,
          writable: true,
          value: patchedFetch
        });
      } catch (_e) {
        window.fetch = patchedFetch;
      }
    }

    if (typeof XMLHttpRequest !== 'undefined' && XMLHttpRequest.prototype && XMLHttpRequest.prototype.open) {
      var origOpen = XMLHttpRequest.prototype.open;
      XMLHttpRequest.prototype.open = function(method, url) {
        var args = Array.prototype.slice.call(arguments);
        args[1] = rewriteUrl(url);
        return origOpen.apply(this, args);
      };
    }

    if (navigator && typeof navigator.sendBeacon === 'function') {
      var origBeacon = navigator.sendBeacon.bind(navigator);
      navigator.sendBeacon = function(url, data) {
        return origBeacon(rewriteUrl(url), data);
      };
    }

    if (typeof window.WebSocket === 'function') {
      var OrigWS = window.WebSocket;
      window.WebSocket = function(url, protocols) {
        return protocols !== undefined ? new OrigWS(rewriteUrl(url), protocols) : new OrigWS(rewriteUrl(url));
      };
      window.WebSocket.prototype = OrigWS.prototype;
      window.WebSocket.CONNECTING = OrigWS.CONNECTING;
      window.WebSocket.OPEN = OrigWS.OPEN;
      window.WebSocket.CLOSING = OrigWS.CLOSING;
      window.WebSocket.CLOSED = OrigWS.CLOSED;
    }

    // Remove the script element so Next.js / React hydration does not observe DOM mismatch.
    if (document.currentScript && document.currentScript.parentNode) {
      document.currentScript.parentNode.removeChild(document.currentScript);
    }
  } catch (_err) {}
})();
</script>
HTML;
    }
}
