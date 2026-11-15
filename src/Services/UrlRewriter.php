<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

/**
 * Rewrites upstream ChatGPT URLs (HTTP, WS, and JSON-escaped) inside proxied HTML,
 * JavaScript, CSS, and JSON payloads to the local Mirror origin, and sanitizes
 * any SSR-embedded OpenAI access tokens.
 */
class UrlRewriter
{
    public const BROWSER_TOKEN = 'mirror-proxied-session';

    public function rewriteText(
        string $body,
        string $localOrigin,
        string $contentType = 'text/html',
        ?string $extraUpstreamOrigin = null,
        string $basePath = ''
    ): string {
        $origin = rtrim($localOrigin, '/');
        if ($origin === '' || $body === '') {
            return $body;
        }

        $normalizedBase = $basePath !== '' ? '/' . trim($basePath, '/') : '';
        if ($normalizedBase === '/') {
            $normalizedBase = '';
        }
        $localRoot = $origin . $normalizedBase;

        $wsOrigin = preg_replace('/^http/i', 'ws', $localRoot) ?: $localRoot;
        $escapedOrigin = str_replace('/', '\\/', $localRoot);
        $escapedPlainOrigin = str_replace('/', '\\/', $origin);
        $escapedWsOrigin = str_replace('/', '\\/', $wsOrigin);

        $replacements = [
            'https://chatgpt.com' => $origin,
            'https://chat.openai.com' => $origin,
            'https://cdn.oaistatic.com' => $origin . '/cdn',
            'wss://chatgpt.com' => $wsOrigin,
            'wss://ws.chatgpt.com' => $wsOrigin,
            'https:\\/\\/chatgpt.com' => $escapedPlainOrigin,
            'https:\\/\\/chat.openai.com' => $escapedPlainOrigin,
            'https:\\/\\/cdn.oaistatic.com' => $escapedPlainOrigin . '\\/cdn',
            'wss:\\/\\/chatgpt.com' => $escapedWsOrigin,
            'wss:\\/\\/ws.chatgpt.com' => $escapedWsOrigin,
        ];

        $extraOrigin = $extraUpstreamOrigin !== null ? rtrim(trim($extraUpstreamOrigin), '/') : '';
        if ($extraOrigin !== '' && $extraOrigin !== $localRoot) {
            $extraWs = preg_replace('/^http/i', 'ws', $extraOrigin) ?: $extraOrigin;
            $escapedExtra = str_replace('/', '\\/', $extraOrigin);
            $escapedExtraWs = str_replace('/', '\\/', $extraWs);
            $replacements[$extraOrigin . '/cdn'] = $origin . '/cdn';
            $replacements[$extraOrigin] = $localRoot;
            $replacements[$extraWs] = $wsOrigin;
            $replacements[$escapedExtra . '\\/cdn'] = $escapedPlainOrigin . '\\/cdn';
            $replacements[$escapedExtra] = $escapedOrigin;
            $replacements[$escapedExtraWs] = $escapedWsOrigin;
        }

        $rewritten = strtr($body, $replacements);

        // Rewrite any remaining ChatGPT subdomains (e.g. ab.chatgpt.com, ws.chatgpt.com, tcr9i.chat.openai.com) matching defcron-mirror
        $chatGptHostsPattern = '(?:[a-z0-9-]+\.)*(?:chatgpt\.com|chat\.openai\.com)';
        $rewritten = preg_replace('#https://' . $chatGptHostsPattern . '#i', $origin, $rewritten) ?? $rewritten;
        $rewritten = preg_replace('#wss://' . $chatGptHostsPattern . '#i', $wsOrigin, $rewritten) ?? $rewritten;
        $rewritten = preg_replace('#https:(?:\\\\/){2}' . $chatGptHostsPattern . '#i', $escapedPlainOrigin, $rewritten) ?? $rewritten;
        $rewritten = preg_replace('#wss:(?:\\\\/){2}' . $chatGptHostsPattern . '#i', $escapedWsOrigin, $rewritten) ?? $rewritten;

        // Disable bundled Datadog initialization in JS payloads so proxy domains never crash
        if (stripos($contentType, 'javascript') !== false) {
            $rewritten = preg_replace('/(?<![.\w])((?:[$\w]+\.)*init\(\{applicationId:)/', 'false&&$1', $rewritten) ?? $rewritten;
        }

        // Rewrite subdomain endpoints like https://ab.chatgpt.com or https://tcr9i.chat.openai.com cleanly if they carry /cdn or backend paths.
        if (stripos($contentType, 'html') !== false || stripos($contentType, 'json') !== false) {
            // Never leak raw OpenAI JWTs from SSR __NEXT_DATA__ or Remix inline scripts.
            $rewritten = preg_replace(
                '/"accessToken"\s*:\s*"[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+"/',
                '"accessToken":"' . self::BROWSER_TOKEN . '"',
                $rewritten
            ) ?? $rewritten;
        }

        return $rewritten;
    }
}
