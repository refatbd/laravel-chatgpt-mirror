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
    /**
     * Regex matching Datadog RUM script tags that fail on proxy origins.
     */
    protected const DATADOG_SRC_SCRIPT_TAG = '/<script\b[^>]*\bsrc=["\'][^"\']*datadoghq[^"\']*["\'][^>]*>\s*<\/script>|<script\b[^>]*\bsrc=["\'][^"\']*datadoghq[^"\']*["\'][^>]*\/>/i';

    /**
     * Regex matching Datadog telemetry initialization calls in bundled JS.
     */
    protected const DATADOG_INIT_CALL_PATTERN = '/(?<![.\w])((?:[$\w]+\.)*init\(\{applicationId:)/';

    /**
     * Strip Datadog script tags out of served HTML so SDK never loads on proxy domains.
     */
    public function stripDatadogScripts(string $html): string
    {
        return preg_replace(self::DATADOG_SRC_SCRIPT_TAG, '', $html) ?? $html;
    }

    /**
     * Disable bundled Datadog telemetry initialization in proxied JavaScript.
     */
    public function disableDatadogInit(string $js): string
    {
        return preg_replace(self::DATADOG_INIT_CALL_PATTERN, 'false&&$1', $js) ?? $js;
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
            'nel',
            'report-to',
            'reporting-endpoints',
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
    public function patchHtml(
        string $html,
        ?string $localOrigin = null,
        string $basePath = '',
        ?string $extraUpstreamOrigin = null
    ): string {
        // Strip any pre-existing early patch injected by an upstream remote mirror node so ours stays authoritative.
        $html = preg_replace('#<script\s+data-mirror-patch="early">[\s\S]*?</script>#i', '', $html) ?? $html;

        // Strip Datadog external script tags to avoid proxy domain mismatch crashes
        $html = $this->stripDatadogScripts($html);

        if ($localOrigin !== null && $localOrigin !== '') {
            $html = $this->rewriter->rewriteText($html, $localOrigin, 'text/html', $extraUpstreamOrigin, $basePath);
        }

        $script = $this->buildEarlyPatchScript($localOrigin ?: '', $basePath, $extraUpstreamOrigin ?: '');

        if (preg_match('/<head[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1] + strlen($matches[0][0]);
            return substr($html, 0, $pos) . "\n" . $script . "\n" . substr($html, $pos);
        }

        return $script . "\n" . $html;
    }

    protected function buildEarlyPatchScript(string $localOrigin, string $basePath = '', string $extraUpstreamOrigin = ''): string
    {
        $normalizedBase = $basePath !== '' ? '/' . trim($basePath, '/') : '';
        if ($normalizedBase === '/') {
            $normalizedBase = '';
        }

        $jsonOrigin = json_encode(rtrim($localOrigin, '/'), JSON_UNESCAPED_SLASHES);
        $jsonBasePath = json_encode($normalizedBase, JSON_UNESCAPED_SLASHES);
        $jsonExtraOrigin = json_encode(rtrim($extraUpstreamOrigin, '/'), JSON_UNESCAPED_SLASHES);
        $backendPrefix = json_encode('/' . trim((string) config('mirror.routes.backend_prefix', 'backend-api'), '/') . '/', JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script data-mirror-patch="early">
(function(){
  try {
    var localOrigin = {$jsonOrigin} || window.location.origin;
    var basePath = {$jsonBasePath};
    var extraUpstream = {$jsonExtraOrigin};
    var localRoot = localOrigin + (basePath || '');
    var backendPrefix = {$backendPrefix};
    var localWs = localOrigin.replace(/^http/i, 'ws');

    function rewriteUrl(u) {
      if (typeof u !== 'string') return u;
      if (u.indexOf('/__codex-api/') !== -1) {
        u = u.replace(/\/__codex-api\//g, backendPrefix);
      }
      if (extraUpstream && u.indexOf(extraUpstream) === 0) {
        u = localOrigin + u.slice(extraUpstream.length);
      }
      u = u.replace(/^https:\/\/(?:[a-z0-9-]+\.)*(?:chat\.openai\.com|chatgpt\.com)/i, localRoot);
      u = u.replace(/^https:\/\/cdn\.oaistatic\.com/i, localOrigin + '/cdn');
      u = u.replace(/^wss:\/\/(?:[a-z0-9-]+\.)*(?:chat\.openai\.com|chatgpt\.com)/i, localWs);
      if (basePath) {
        if (u.indexOf('/backend-api/') === 0 || u.indexOf('/api/') === 0 || u.indexOf('/public-api/') === 0 || u.indexOf('/cdn/') === 0) {
          return localOrigin + u;
        }
        if (u.charAt(0) === '/' && u.charAt(1) !== '/' && u.indexOf(basePath + '/') !== 0 && u.indexOf('/_unigpt/') !== 0 && u.indexOf('/ces/') !== 0) {
          u = basePath + u;
        }
      }
      if (u.indexOf('https://ab.chatgpt.com') === 0 || u.indexOf('https://rum.browser-intake-datadoghq.com') === 0) {
        return localOrigin + '/ces/noop';
      }
      return u;
    }

    function rel(u) {
      try {
        if (typeof u !== 'string') return u;
        var x = new URL(u, window.location.href);
        if (x.hostname === 'chatgpt.com' || x.hostname.endsWith('.chatgpt.com') || x.hostname === 'chat.openai.com') {
          x.protocol = window.location.protocol;
          x.host = window.location.host;
          return rewriteUrl(x.href);
        }
      } catch (_e) {}
      return rewriteUrl(u);
    }

    function wrapFetch(delegate) {
      var wrapped = function(input, init) {
        try {
          if (typeof input === 'string') {
            input = rel(input);
          } else if (input instanceof URL) {
            input = rel(input.href);
          } else if (input && typeof input === 'object' && typeof input.url === 'string') {
            var rewritten = rel(input.url);
            if (rewritten !== input.url) {
              var base = {
                method: input.method, headers: input.headers, mode: input.mode,
                credentials: input.credentials, cache: input.cache, redirect: input.redirect,
                referrer: input.referrer, referrerPolicy: input.referrerPolicy,
                integrity: input.integrity, keepalive: input.keepalive, signal: input.signal
              };
              if (input.method !== 'GET' && input.method !== 'HEAD') {
                return input.clone().arrayBuffer().then(function(buf) {
                  base.body = buf;
                  if (init) for (var key in init) base[key] = init[key];
                  return delegate.call(this, rewritten, base);
                }.bind(this));
              }
              if (init) for (var key in init) base[key] = init[key];
              input = rewritten;
              init = base;
            }
          }
        } catch (_err) {}
        return delegate.call(this, input, init);
      };
      try { Object.defineProperty(wrapped, '__mirrorProxyPatch', { value: true }); } catch (_e) {}
      return wrapped;
    }

    var currentFetch = window.fetch;
    if (typeof currentFetch === 'function') {
      var patchedFetch = wrapFetch(currentFetch);
      try {
        Object.defineProperty(window, 'fetch', {
          configurable: false,
          enumerable: true,
          get: function() { return patchedFetch; },
          set: function(next) {
            if (typeof next === 'function' && !next.__mirrorProxyPatch) {
              currentFetch = next;
              patchedFetch = wrapFetch(next);
            }
          }
        });
      } catch (_e) {
        window.fetch = patchedFetch;
      }
    }

    if (typeof XMLHttpRequest !== 'undefined' && XMLHttpRequest.prototype && XMLHttpRequest.prototype.open) {
      var origOpen = XMLHttpRequest.prototype.open;
      XMLHttpRequest.prototype.open = function(method, url) {
        var args = Array.prototype.slice.call(arguments);
        try { if (typeof url === 'string') args[1] = rel(url); } catch (_e) {}
        return origOpen.apply(this, args);
      };
    }

    var OrigWorker = window.Worker;
    if (typeof OrigWorker === 'function') {
      window.Worker = function(url, opts) {
        try { url = rel(String(url)); } catch (_e) {}
        return opts !== undefined ? new OrigWorker(url, opts) : new OrigWorker(url);
      };
      window.Worker.prototype = OrigWorker.prototype;
    }

    if (navigator && typeof navigator.sendBeacon === 'function') {
      var origBeacon = navigator.sendBeacon.bind(navigator);
      navigator.sendBeacon = function(url, data) {
        try { if (typeof url === 'string') url = rel(url); } catch (_e) {}
        return origBeacon(url, data);
      };
    }

    if (typeof window.WebSocket === 'function') {
      var OrigWS = window.WebSocket;
      window.WebSocket = function(url, protocols) {
        try {
          if (typeof url === 'string') url = rel(url).replace(/^https:/i, 'wss:').replace(/^http:/i, 'ws:');
        } catch (_e) {}
        return protocols !== undefined ? new OrigWS(url, protocols) : new OrigWS(url);
      };
      window.WebSocket.prototype = OrigWS.prototype;
      window.WebSocket.CONNECTING = OrigWS.CONNECTING;
      window.WebSocket.OPEN = OrigWS.OPEN;
      window.WebSocket.CLOSING = OrigWS.CLOSING;
      window.WebSocket.CLOSED = OrigWS.CLOSED;
    }

    if (basePath && window.history) {
      ['pushState', 'replaceState'].forEach(function(fn) {
        var orig = window.history[fn];
        if (typeof orig === 'function') {
          window.history[fn] = function(state, title, url) {
            if (typeof url === 'string' && url.charAt(0) === '/' && url.charAt(1) !== '/' && url.indexOf(basePath + '/') !== 0 && url !== basePath) {
              url = basePath + url;
            }
            return orig.call(this, state, title, url);
          };
        }
      });
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
