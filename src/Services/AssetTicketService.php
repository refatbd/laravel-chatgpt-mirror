<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

/**
 * Creates and validates sealed, account-scoped 7-day asset preview/download URLs
 * served at GET /api/asset-content without exposing bearer credentials to <img> tags.
 */
class AssetTicketService
{
    public const TTL_SECONDS = 7 * 86400;

    public function __construct(
        protected SecurityService $security
    ) {}

    /**
     * @return array{url: string, downloadUrl: string, ticket: string, sig: string}
     */
    public function createSignedLinks(
        string $baseUrl,
        string $pointer,
        ?string $upstreamConversationId = null,
        ?string $messageId = null,
        ?string $accountId = null,
        ?string $fileName = null,
        bool $isImage = false
    ): array {
        $payload = [
            'pointer' => $pointer,
            'conversationId' => $upstreamConversationId,
            'messageId' => $messageId,
            'accountId' => $accountId ?: 'default',
            'fileName' => $fileName,
            'gen' => $this->security->assetLinkGeneration(),
            'exp' => time() + self::TTL_SECONDS,
        ];

        $ticket = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = $this->sign($ticket);
        $base = rtrim($baseUrl, '/') . '/api/asset-content?ticket=' . urlencode($ticket) . '&sig=' . urlencode($sig);

        return [
            'url' => $isImage ? $base : $base . '&download=1',
            'downloadUrl' => $base . '&download=1',
            'ticket' => $ticket,
            'sig' => $sig,
        ];
    }

    /**
     * Validate ticket signature, expiration, and link generation.
     *
     * @return array<string, mixed>|null
     */
    public function verifyTicket(string $ticket, string $sig): ?array
    {
        if ($ticket === '' || $sig === '') {
            return null;
        }

        $expected = $this->sign($ticket);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $raw = $this->base64UrlDecode($ticket);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['pointer']) || !isset($data['exp'])) {
            return null;
        }

        if ((int) $data['exp'] < time()) {
            return null;
        }

        $currentGen = $this->security->assetLinkGeneration();
        if (!empty($data['gen']) && !hash_equals($currentGen, (string) $data['gen'])) {
            return null;
        }

        return $data;
    }

    public static function sanitizeDownloadFilename(?string $name): string
    {
        $clean = basename(str_replace(['\\', "\0", "\r", "\n"], '/', (string) $name));
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $clean) ?? 'download';
        $clean = ltrim($clean, '.');
        return $clean !== '' ? $clean : 'download.bin';
    }

    protected function sign(string $ticket): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $ticket, $this->security->hmacSecret(), true));
    }

    protected function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $encoded): ?string
    {
        $pad = (4 - (strlen($encoded) % 4)) % 4;
        $decoded = base64_decode(strtr($encoded . str_repeat('=', $pad), '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
