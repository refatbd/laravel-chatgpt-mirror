<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

class BrowserPatchManager
{
    /**
     * Inspect and clean response headers to remove restrictive CSP.
     */
    public function cleanHeaders(array $headers): array
    {
        $filtered = [];
        $disallowed = [
            'content-security-policy',
            'content-security-policy-report-only',
            'x-content-security-policy',
            'x-webkit-csp',
            'cross-origin-embedder-policy',
            'cross-origin-opener-policy',
        ];

        foreach ($headers as $key => $values) {
            $lower = strtolower($key);
            if (!in_array($lower, $disallowed, true)) {
                $filtered[$key] = $values;
            }
        }

        return $filtered;
    }

    /**
     * Inject scripts into the HTML response before </head> or <body>.
     */
    public function patchHtml(string $html, ?string $sessionToken = null, ?string $customBackendPrefix = null): string
    {
        // 1. Strip Datadog scripts to prevent console noise and telemetry blocks
        $datadogPattern = '/<script\b[^>]*\bsrc=["\'][^"\']*datadoghq[^"\']*["\'][^>]*>\s*<\/script>|<script\b[^>]*\bsrc=["\'][^"\']*datadoghq[^"\']*["\'][^>]*\/>/i';
        $cleanedHtml = preg_replace($datadogPattern, '', $html);

        $prefix = $customBackendPrefix;
        if ($prefix === null) {
            $prefix = (function_exists('config') && function_exists('app') && app()->bound('config'))
                ? config('mirror.routes.backend_prefix', 'backend-api')
                : 'backend-api';
        }

        $backendPrefix = '/' . ltrim($prefix, '/');

        $script = <<<HTML
<script>
(function() {
    try {
        localStorage.setItem("codex.browser.apiBaseUrl", "{$backendPrefix}");
    } catch (e) {}

    // Intercept client-side fetch calls to rewrite /__codex-api/ to /backend-api/
    var originalFetch = window.fetch;
    window.fetch = function(resource, init) {
        if (typeof resource === 'string') {
            if (resource.indexOf('/__codex-api/') !== -1) {
                resource = resource.replace('/__codex-api/', '{$backendPrefix}/');
            }
        } else if (resource && resource.url && resource.url.indexOf('/__codex-api/') !== -1) {
            resource = new Request(resource.url.replace('/__codex-api/', '{$backendPrefix}/'), resource);
        }
        return originalFetch.apply(this, arguments);
    };

    // Inject native Mirror Controls in ChatGPT Sidebar
    function injectMirrorControls() {
        if (document.getElementById('laravel-mirror-launcher')) return;
        var allProfiles = Array.from(document.querySelectorAll('button[aria-label="Open profile menu"], [data-testid="accounts-profile-button"]'));
        var anchor = allProfiles.find(function(btn) {
            var r = btn.getBoundingClientRect();
            return r.width > 0 && r.height > 0 && window.getComputedStyle(btn).opacity !== '0';
        });

        if (!anchor) {
            var buttons = document.querySelectorAll('button');
            for (var i = 0; i < buttons.length; i++) {
                if ((buttons[i].textContent || '').trim() === 'Log in' && !buttons[i].closest('#page-header')) {
                    anchor = buttons[i];
                    break;
                }
            }
        }
        if (!anchor) return;

        var container = anchor.closest('.min-w-0.flex-1')?.parentElement || anchor.parentElement?.parentElement;
        if (!container || !container.parentElement) return;

        var btn = document.createElement('div');
        btn.id = 'laravel-mirror-launcher';
        btn.style.margin = '4px 12px 6px 12px';
        btn.innerHTML = '<a href="/mirror/admin" target="_blank" style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:rgba(16,163,127,0.15);border:1px solid rgba(16,163,127,0.35);border-radius:8px;color:#10a37f;text-decoration:none;font-size:12px;font-weight:600;font-family:sans-serif;box-shadow:0 2px 6px rgba(0,0,0,0.2);"><span style="width:8px;height:8px;border-radius:50%;background:#10a37f;box-shadow:0 0 6px #10a37f;flex:none;"></span><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">⚡ Mirror Settings &amp; Pool</span></a>';

        container.parentElement.insertBefore(btn, container);
    }

    setInterval(injectMirrorControls, 1200);
})();
</script>
HTML;

        if (stripos($cleanedHtml, '</head>') !== false) {
            return str_ireplace('</head>', $script . "\n</head>", $cleanedHtml);
        }

        if (stripos($cleanedHtml, '<body') !== false) {
            return preg_replace('/(<body[^>]*>)/i', "$1\n" . $script, $cleanedHtml, 1);
        }

        return $script . "\n" . $cleanedHtml;
    }
}
