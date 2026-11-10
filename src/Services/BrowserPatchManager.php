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
