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

    public function rewriteText(string $body, string $localOrigin, string $contentType = 'text/html'): string
    {
        $origin = rtrim($localOrigin, '/');
        if ($origin === '' || $body === '') {
            return $body;
        }

        $wsOrigin = preg_replace('/^http/i', 'ws', $origin) ?: $origin;
        $escapedOrigin = str_replace('/', '\\/', $origin);
        $escapedWsOrigin = str_replace('/', '\\/', $wsOrigin);

        $replacements = [
            'https://chatgpt.com' => $origin,
            'https://chat.openai.com' => $origin,
            'https://cdn.oaistatic.com' => $origin . '/cdn',
            'wss://chatgpt.com' => $wsOrigin,
            'wss://ws.chatgpt.com' => $wsOrigin,
            'https:\\/\\/chatgpt.com' => $escapedOrigin,
            'https:\\/\\/chat.openai.com' => $escapedOrigin,
            'https:\\/\\/cdn.oaistatic.com' => $escapedOrigin . '\\/cdn',
            'wss:\\/\\/chatgpt.com' => $escapedWsOrigin,
            'wss:\\/\\/ws.chatgpt.com' => $escapedWsOrigin,
        ];

        $rewritten = strtr($body, $replacements);

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
