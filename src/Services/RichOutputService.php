<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

/**
 * Resolves generated ChatGPT attachments (sediment://, file-service://, sandbox:/),
 * inline web citations (\u{E200}...\u{E201}), inline widgets (`entity[...]`, `image_group{...}`),
 * and tool outputs into Markdown with signed 7-day preview/download URLs.
 */
class RichOutputService
{
    public function __construct(
        protected ChatGptClient $client,
        protected AssetTicketService $tickets
    ) {}

    /**
     * @param array<int, array<string, mixed>> $events
     */
    public function needsRichOutput(array $events, string $fallbackText = ''): bool
    {
        foreach ($events as $ev) {
            if (!empty($ev['displayHidden'])) {
                continue;
            }
            $kind = (string) ($ev['kind'] ?? '');
            if (in_array($kind, ['tool', 'image', 'file', 'citation', 'citation_patch'], true)) {
                return true;
            }
            if ($kind === 'message') {
                $contentType = (string) ($ev['raw']['content']['content_type'] ?? '');
                if ($contentType === 'reasoning_recap' || $contentType === 'summary') {
                    return true;
                }
            }
            if ($kind === 'assistant_text' && $this->containsRichMarkers((string) ($ev['text'] ?? ''))) {
                return true;
            }
        }

        return $this->containsRichMarkers($fallbackText);
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array{
     *   text: string,
     *   assets: array<int, array<string, mixed>>,
     *   tools: array<int, array<string, mixed>>,
     *   summaries: array<int, array{messageId: ?string, text: string}>,
     *   metadata: array<string, string>
     * }
     */
    public function render(
        array $events,
        string $fallbackText,
        string $baseUrl,
        ?MirrorToken $token,
        ?string $upstreamConversationId,
        ?string $defaultMessageId
    ): array {
        $text = $fallbackText;
        $pointers = [];
        $citationRefs = [];
        $tools = [];
        $summaries = [];

        foreach ($events as $ev) {
            if (!empty($ev['displayHidden'])) {
                continue;
            }
            $kind = (string) ($ev['kind'] ?? '');
            $msgId = $ev['messageId'] ?? $defaultMessageId;

            if ($kind === 'image' || $kind === 'file') {
                $ptr = (string) ($ev['assetPointer'] ?? '');
                if ($ptr !== '') {
                    $pointers[$ptr] = [
                        'image' => $kind === 'image' || str_starts_with($ptr, 'sediment://'),
                        'title' => (string) ($ev['title'] ?? 'Download file'),
                        'messageId' => $msgId,
                    ];
                }
            } elseif ($kind === 'tool') {
                $tools[] = [
                    'name' => (string) ($ev['name'] ?? 'tool'),
                    'status' => $ev['status'] ?? null,
                    'messageId' => $msgId,
                    'text' => $this->extractToolText($ev['raw'] ?? []),
                ];
            } elseif ($kind === 'message' && is_array($ev['raw'] ?? null)) {
                $contentType = (string) ($ev['raw']['content']['content_type'] ?? '');
                if ($contentType === 'reasoning_recap' || $contentType === 'summary') {
                    $sumText = (string) ($ev['raw']['content']['text'] ?? '');
                    if ($sumText !== '') {
                        $summaries[] = ['messageId' => $msgId, 'text' => $sumText];
                    }
                }
                $this->collectContentReferences($ev['raw']['metadata']['content_references'] ?? [], $citationRefs);
                $this->collectContentReferences($ev['raw']['metadata']['citations'] ?? [], $citationRefs);
            } elseif ($kind === 'citation_patch') {
                $this->collectContentReferences($ev['contentReferences'] ?? [], $citationRefs);
            }
        }

        // Scan text for bare asset pointers (`file-service://`, `sediment://`, `sandbox:/`).
        if (preg_match_all('/(?:file-service|sediment):\/\/[^\s\)\]\'"<>\x{E000}-\x{F8FF}]+|sandbox:\/[^\s\)\]\'"<>\x{E000}-\x{F8FF}]+/u', $text, $matches)) {
            foreach ($matches[0] as $ptr) {
                if (!isset($pointers[$ptr])) {
                    $pointers[$ptr] = [
                        'image' => str_starts_with($ptr, 'sediment://'),
                        'title' => 'Download file',
                        'messageId' => $defaultMessageId,
                    ];
                }
            }
        }

        // Resolve pointer -> signed 7-day /api/asset-content URLs
        $assets = [];
        $resolvedUrls = [];

        foreach ($pointers as $ptr => $info) {
            if ($token === null) {
                $assets[] = ['pointer' => $ptr, 'status' => 'unavailable'];
                continue;
            }

            try {
                $meta = $this->client->resolveAssetDownloadMetadata(
                    $token,
                    $ptr,
                    $upstreamConversationId,
                    is_string($info['messageId']) ? $info['messageId'] : null
                );

                $fileName = $meta['file_name'] ?: ($info['title'] !== 'Download file' ? $info['title'] : basename($ptr));
                $signed = $this->tickets->createSignedLinks(
                    $baseUrl,
                    $ptr,
                    $upstreamConversationId,
                    is_string($info['messageId']) ? $info['messageId'] : null,
                    $token->getEffectiveAccountId(),
                    $fileName,
                    (bool) $info['image']
                );

                $resolvedUrls[$ptr] = array_merge($signed, [
                    'fileName' => $fileName,
                    'image' => (bool) $info['image'],
                ]);
                $assets[] = [
                    'pointer' => $ptr,
                    'url' => $signed['url'],
                    'downloadUrl' => $signed['downloadUrl'],
                    'status' => 'resolved',
                ];
            } catch (\Throwable) {
                $assets[] = ['pointer' => $ptr, 'status' => 'unavailable'];
            }
        }

        // Rewrite citation tokens (\u{E200}...\u{E201}) and inline widgets (`entity`, `image_group`).
        if ($citationRefs !== []) {
            foreach ($citationRefs as $matched => $ref) {
                $replacement = !empty($ref['url'])
                    ? "[{$ref['title']}](<{$ref['url']}>)"
                    : $ref['title'];
                $text = str_replace($matched, $replacement, $text);
            }
        }

        $text = preg_replace_callback('/\x{E200}([^\x{E201}]*)\x{E201}/u', function (array $m): string {
            $inner = $m[1];
            if (preg_match('/^([a-zA-Z_]\w*)\x{E202}?([[{][\s\S]*[\]}])$/u', $inner, $w)) {
                $payload = json_decode($w[2], true);
                if ($w[1] === 'entity' && is_array($payload)) {
                    return (string) ($payload[1] ?? $payload[0] ?? '');
                }
                return '';
            }
            return '[Reference unavailable]';
        }, $text) ?? $text;

        // Replace asset pointers in Markdown links/text with signed preview/download links
        $used = [];
        foreach ($resolvedUrls as $ptr => $linkData) {
            if (str_contains($text, $ptr)) {
                $used[$ptr] = true;
                $label = AssetTicketService::sanitizeDownloadFilename($linkData['fileName']);
                $replacement = $linkData['image']
                    ? "![{$label}](<{$linkData['url']}>)\n\nDownload file: [{$label}](<{$linkData['downloadUrl']}>)"
                    : "Download file: [{$label}](<{$linkData['downloadUrl']}>)";
                $text = str_replace($ptr, $replacement, $text);
            }
        }

        foreach ($resolvedUrls as $ptr => $linkData) {
            if (!isset($used[$ptr])) {
                $label = AssetTicketService::sanitizeDownloadFilename($linkData['fileName']);
                $text .= $linkData['image']
                    ? "\n\n![{$label}](<{$linkData['url']}>)\n\nDownload file: [{$label}](<{$linkData['downloadUrl']}>)"
                    : "\n\nDownload file: [{$label}](<{$linkData['downloadUrl']}>)";
            }
        }

        // Pack mirror_* metadata matching Node.js OpenAI response schema
        $metadata = [];
        if ($assets !== []) {
            $metadata['mirror_assets'] = json_encode($assets, JSON_UNESCAPED_SLASHES);
            $imgAssets = array_values(array_filter(
                array_map(fn ($a) => !empty($a['url']) && ($pointers[$a['pointer']]['image'] ?? false) ? ['url' => $a['url']] : null, $assets)
            ));
            if ($imgAssets !== []) {
                $metadata['mirror_images'] = json_encode($imgAssets, JSON_UNESCAPED_SLASHES);
            }
        }
        if ($tools !== []) {
            $metadata['mirror_tool_outputs'] = json_encode($tools, JSON_UNESCAPED_SLASHES);
            $metadata['mirror_tool_events'] = json_encode(
                array_map(fn ($t) => ['name' => $t['name'], 'status' => $t['status']], $tools)
            );
        }
        if ($summaries !== []) {
            $metadata['mirror_reasoning_summaries'] = json_encode($summaries, JSON_UNESCAPED_SLASHES);
        }

        return [
            'text' => $text,
            'assets' => $assets,
            'tools' => $tools,
            'summaries' => $summaries,
            'metadata' => $metadata,
        ];
    }

    protected function containsRichMarkers(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        return (bool) preg_match('/[\x{E000}-\x{F8FF}]|sandbox:|file-service:|sediment:/u', $text);
    }

    /**
     * @param mixed $refs
     * @param array<string, array{title: string, url: ?string}> $out
     */
    protected function collectContentReferences(mixed $refs, array &$out): void
    {
        if (!is_array($refs)) {
            return;
        }

        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $matched = (string) ($ref['matched_text'] ?? '');
            if ($matched === '' || isset($out[$matched]) || !empty($ref['file_id'])) {
                continue;
            }
            $primary = is_array($ref['items'][0] ?? null) ? $ref['items'][0] : $ref;
            $title = (string) ($primary['title'] ?? $primary['name'] ?? $primary['attribution'] ?? $ref['title'] ?? $ref['name'] ?? '');
            $url = (string) ($primary['url'] ?? $ref['url'] ?? $primary['cloud_doc_url'] ?? '');
            if ($title !== '' || $url !== '') {
                $out[$matched] = ['title' => $title ?: $url, 'url' => $url !== '' ? $url : null];
            }
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function extractToolText(array $raw): string
    {
        $chunks = [];
        foreach ([$raw, $raw['content'] ?? [], $raw['output'] ?? []] as $container) {
            if (!is_array($container)) {
                continue;
            }
            foreach (['text', 'stdout', 'stderr', 'logs'] as $key) {
                if (!empty($container[$key])) {
                    $val = is_array($container[$key]) ? implode("\n", $container[$key]) : (string) $container[$key];
                    if ($val !== '') {
                        $chunks[] = "{$key}: {$val}";
                    }
                }
            }
        }
        return implode("\n\n", array_unique($chunks));
    }
}
