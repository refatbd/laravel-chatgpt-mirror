<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Refatbd\LaravelChatGptMirror\Models\MirrorConversation;
use Refatbd\LaravelChatGptMirror\Models\MirrorMessage;
use Refatbd\LaravelChatGptMirror\Models\MirrorSetting;
use Refatbd\LaravelChatGptMirror\Models\MirrorToken;

/**
 * Manages persisted Mirror conversations, message trees, remote ChatGPT sidebar sync,
 * transcript continuation/rebase logic, search, branching, export, and turn cancellation.
 */
class ConversationService
{
    public function __construct(
        protected ChatGptClient $client,
        protected TokenPoolManager $pool
    ) {}

    public function createConversation(
        string $accountId,
        string $model = 'auto',
        ?string $gizmoId = null,
        ?string $publicId = null,
        bool $isPrivate = false,
        ?string $title = null
    ): MirrorConversation {
        $id = $publicId ?: (string) Str::uuid();

        return MirrorConversation::create([
            'public_id' => $id,
            'conversation_id' => 'local:' . $id,
            'upstream_conversation_id' => null,
            'account_id' => $accountId ?: 'default',
            'model' => $model ?: 'auto',
            'gizmo_id' => $gizmoId,
            'is_private' => $isPrivate,
            'title' => $title ?: 'New chat',
            'current_node_id' => 'client-created-root',
            'last_active_at' => now(),
        ]);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, hasMore: bool}
     */
    public function listConversations(string $accountId, int $limit = 50, int $offset = 0, bool $sync = false, bool $resync = false): array
    {
        if ($sync) {
            $token = $this->pool->acquireForAccount(null, $accountId);
            if ($token) {
                try {
                    $cursorKey = 'conversation_sync_cursor:' . $accountId;
                    $syncOffset = $resync ? 0 : (int) MirrorSetting::getValue($cursorKey, '0');
                    $page = $this->client->fetchConversations($token, $syncOffset, min(50, max(20, $limit)));
                    $items = is_array($page['items'] ?? null) ? $page['items'] : [];
                    foreach ($items as $item) {
                        if (!is_array($item) || empty($item['id'])) {
                            continue;
                        }
                        $this->upsertRemoteSummary($accountId, $item);
                    }
                    $totalRemote = (int) ($page['total'] ?? 0);
                    $nextOffset = $syncOffset + count($items);
                    MirrorSetting::setValue($cursorKey, ($nextOffset >= $totalRemote || $items === []) ? '0' : (string) $nextOffset);
                    $this->pool->release($token, true);
                } catch (\Throwable) {
                    $this->pool->release($token, false);
                }
            }
        }

        $query = MirrorConversation::where('account_id', $accountId)
            ->orderByDesc('last_active_at')
            ->orderByDesc('updated_at');

        $total = $query->count();
        $records = $query->skip($offset)->take($limit)->get();

        return [
            'items' => $records->map(fn (MirrorConversation $c) => $c->toPlaygroundArray())->all(),
            'total' => $total,
            'hasMore' => ($offset + $records->count()) < $total,
        ];
    }

    /**
     * @return array{query: string, items: array<int, array<string, mixed>>, total: int}
     */
    public function searchConversations(string $accountId, string $query, int $limit = 50): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            $list = $this->listConversations($accountId, $limit, 0, false, false);
            return ['query' => '', 'items' => $list['items'], 'total' => $list['total']];
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $trimmed) . '%';

        $matchingMessageConvIds = MirrorMessage::where('content', 'like', $like)
            ->pluck('conversation_public_id')
            ->unique()
            ->all();

        $q = MirrorConversation::where('account_id', $accountId)
            ->where(function ($sub) use ($like, $matchingMessageConvIds): void {
                $sub->where('title', 'like', $like)
                    ->orWhere('public_id', 'like', $like);
                if ($matchingMessageConvIds !== []) {
                    $sub->orWhereIn('public_id', $matchingMessageConvIds);
                }
            })
            ->orderByDesc('last_active_at');

        $total = $q->count();
        $items = $q->take($limit)->get()->map(fn (MirrorConversation $c) => $c->toPlaygroundArray())->all();

        return [
            'query' => $trimmed,
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array{conversation: MirrorConversation, messages: array<int, array<string, mixed>>, instructions: array<int, array<string, mixed>>}|null
     */
    public function getConversationDetail(string $identifier, string $accountId): ?array
    {
        $conv = MirrorConversation::findByIdentifier($identifier);
        if (!$conv) {
            return null;
        }

        if ($conv->account_id !== $accountId && $conv->account_id !== 'default' && $accountId !== 'default') {
            return null;
        }

        $publicId = $conv->public_id ?: (string) $conv->id;
        $messages = MirrorMessage::where('conversation_public_id', $publicId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Lazy-import upstream tree when empty locally and upstream ID is known
        $upstreamId = $conv->effectiveUpstreamConversationId();
        if ($messages->isEmpty() && $upstreamId) {
            $token = $this->pool->acquireForAccount($conv->token_id ? (string) $conv->token_id : null, $accountId);
            if ($token) {
                try {
                    $remote = $this->client->fetchConversation($token, $upstreamId);
                    $this->importRemoteConversationTree($conv, $remote);
                    $this->pool->release($token, true);
                    $messages = MirrorMessage::where('conversation_public_id', $publicId)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get();
                } catch (\Throwable) {
                    $this->pool->release($token, false);
                }
            }
        }

        return [
            'conversation' => $conv,
            'messages' => $messages->map(fn (MirrorMessage $m) => $m->toPlaygroundArray())->all(),
            'instructions' => is_array($conv->instructions_payload) ? $conv->instructions_payload : [],
        ];
    }

    public function branchConversation(
        MirrorConversation $source,
        string $messageId,
        ?string $title = null
    ): ?MirrorConversation {
        $sourcePublicId = $source->public_id ?: (string) $source->id;
        $target = MirrorMessage::where('conversation_public_id', $sourcePublicId)
            ->where('id', $messageId)
            ->first();

        if (!$target || !$target->upstream_node_id) {
            return null;
        }

        $branchId = (string) Str::uuid();
        $branch = MirrorConversation::create([
            'public_id' => $branchId,
            'conversation_id' => $source->effectiveUpstreamConversationId() ?: ('local:' . $branchId),
            'upstream_conversation_id' => $source->effectiveUpstreamConversationId(),
            'account_id' => $source->account_id,
            'token_id' => $source->token_id,
            'model' => $source->model,
            'gizmo_id' => $source->gizmo_id,
            'is_private' => $source->is_private,
            'is_branch' => true,
            'branched_from_id' => $sourcePublicId,
            'branched_from_message_id' => $messageId,
            'initialized' => $source->initialized,
            'init_payload' => $source->init_payload,
            'instructions_payload' => $source->instructions_payload,
            'current_node_id' => $target->upstream_node_id,
            'title' => $title ?: ('Branch: ' . ($source->title ?: 'Conversation')),
            'last_active_at' => now(),
        ]);

        // Copy messages up through the branch point
        $all = MirrorMessage::where('conversation_public_id', $sourcePublicId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($all as $msg) {
            MirrorMessage::create([
                'id' => (string) Str::uuid(),
                'conversation_public_id' => $branchId,
                'upstream_node_id' => $msg->upstream_node_id,
                'role' => $msg->role,
                'content' => $msg->content,
                'status' => $msg->status,
                'events_payload' => $msg->events_payload,
                'attachments_payload' => $msg->attachments_payload,
            ]);
            if ($msg->id === $target->id) {
                break;
            }
        }

        return $branch;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBranches(MirrorConversation $conv): array
    {
        $publicId = $conv->public_id ?: (string) $conv->id;
        return MirrorConversation::where('branched_from_id', $publicId)
            ->orWhere(function ($q) use ($conv): void {
                if ($conv->branched_from_id) {
                    $q->where('public_id', $conv->branched_from_id);
                }
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MirrorConversation $c) => $c->toPlaygroundArray())
            ->all();
    }

    public function exportMarkdown(MirrorConversation $conv): string
    {
        $publicId = $conv->public_id ?: (string) $conv->id;
        $messages = MirrorMessage::where('conversation_public_id', $publicId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $lines = [
            '# ' . ($conv->title ?: 'Conversation'),
            '',
            '- **Conversation ID:** `' . $publicId . '`',
            '- **Model:** `' . ($conv->model ?: 'auto') . '`',
            '- **Updated:** ' . ($conv->last_active_at ?? $conv->updated_at ?? now())->toIso8601String(),
            '',
            '---',
            '',
        ];

        foreach ($messages as $m) {
            $heading = ucfirst($m->role);
            $lines[] = "### {$heading}";
            $lines[] = '';
            $lines[] = (string) $m->content;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function requestStop(string $publicId): void
    {
        Cache::put('mirror:stop:' . $publicId, true, 300);
    }

    public function isStopRequested(string $publicId): bool
    {
        return (bool) Cache::get('mirror:stop:' . $publicId, false);
    }

    public function clearStop(string $publicId): void
    {
        Cache::forget('mirror:stop:' . $publicId);
    }

    public function recordTurnMessages(
        MirrorConversation $conv,
        string $userContent,
        string $assistantContent,
        ?string $userNodeId,
        ?string $assistantNodeId,
        array $events = [],
        array $attachments = [],
        array $instructions = [],
        ?string $contextHash = null,
        ?string $transcriptHash = null
    ): MirrorMessage {
        $publicId = $conv->public_id ?: (string) $conv->id;

        MirrorMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_public_id' => $publicId,
            'upstream_node_id' => $userNodeId,
            'role' => 'user',
            'content' => $userContent,
            'status' => 'done',
            'events_payload' => [],
            'attachments_payload' => $attachments,
        ]);

        $assistant = MirrorMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_public_id' => $publicId,
            'upstream_node_id' => $assistantNodeId,
            'role' => 'assistant',
            'content' => $assistantContent,
            'status' => 'done',
            'events_payload' => $events,
            'attachments_payload' => [],
        ]);

        $conv->update([
            'instructions_payload' => $instructions !== [] ? $instructions : $conv->instructions_payload,
            'openai_context_hash' => $contextHash ?: $conv->openai_context_hash,
            'openai_transcript_hash' => $transcriptHash ?: $conv->openai_transcript_hash,
            'current_node_id' => $assistantNodeId ?: $conv->current_node_id,
            'last_active_at' => now(),
        ]);

        return $assistant;
    }

    /**
     * Resolve OpenAI `image_url` or `file` parts safely (data: URI or public HTTP(S) URL with SSRF guard)
     * and upload via ChatGptClient.
     *
     * @param array<int, array<string, mixed>> $rawMessages
     * @return array<int, array<string, mixed>>
     */
    public function uploadOpenAiAttachments(MirrorToken $token, array $rawMessages, string $accountId): array
    {
        $uploaded = [];
        $lastUser = null;
        foreach (array_reverse($rawMessages) as $m) {
            if (($m['role'] ?? '') === 'user') {
                $lastUser = $m;
                break;
            }
        }
        if (!$lastUser || !is_array($lastUser['content'] ?? null)) {
            return [];
        }

        foreach ($lastUser['content'] as $idx => $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? '');
            if ($type === 'image_url' && !empty($part['image_url']['url'])) {
                $url = (string) $part['image_url']['url'];
                ['bytes' => $bytes, 'mime' => $mime] = $this->fetchSafeAttachmentBytes($url);
                $ext = explode('+', explode('/', $mime)[1] ?? 'png')[0] ?: 'png';
                $att = $this->client->uploadFile($token, $bytes, "image-{$idx}.{$ext}", $mime);
                $uploaded[] = $att;
            } elseif (($type === 'file' || $type === 'input_file') && !empty($part['file']['file_data'])) {
                $url = (string) $part['file']['file_data'];
                $fileName = trim((string) ($part['file']['filename'] ?? "attachment-{$idx}.bin"));
                ['bytes' => $bytes, 'mime' => $mime] = $this->fetchSafeAttachmentBytes($url);
                $att = $this->client->uploadFile($token, $bytes, $fileName, $mime);
                $uploaded[] = $att;
            }
        }

        return $uploaded;
    }

    public function isPublicHostname(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $resolved = gethostbyname($host);
        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
            return filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        return true;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    protected function fetchSafeAttachmentBytes(string $url): array
    {
        if (preg_match('/^data:([^;,]+)(;charset=[^;,]+)?(;base64)?,(.*)$/s', $url, $m)) {
            $mime = $m[1];
            $isBase64 = !empty($m[3]);
            $payload = $m[4];
            $bytes = $isBase64 ? base64_decode($payload, true) : rawurldecode($payload);
            if (!is_string($bytes) || $bytes === '') {
                throw new \InvalidArgumentException('Invalid data URI attachment payload');
            }
            if (strlen($bytes) > 20 * 1024 * 1024) {
                throw new \InvalidArgumentException('Attachment exceeds 20 MB limit');
            }
            return ['bytes' => $bytes, 'mime' => $mime];
        }

        if (!preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Attachment URL must be a data: URI or public http(s) URL');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!$this->isPublicHostname($host)) {
            throw new \InvalidArgumentException("Attachment host is not permitted: {$host}");
        }

        $client = new Client(['timeout' => 30, 'allow_redirects' => false, 'http_errors' => false]);
        $res = $client->get($url);
        if ($res->getStatusCode() >= 400) {
            throw new \RuntimeException("Failed fetching attachment URL: HTTP {$res->getStatusCode()}");
        }

        $bytes = (string) $res->getBody();
        if ($bytes === '' || strlen($bytes) > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('Fetched attachment is empty or exceeds 20 MB');
        }

        $mime = trim(explode(';', $res->getHeaderLine('Content-Type') ?: 'application/octet-stream')[0]);
        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function upsertRemoteSummary(string $accountId, array $item): void
    {
        $upstreamId = (string) $item['id'];
        $existing = MirrorConversation::where('upstream_conversation_id', $upstreamId)
            ->orWhere('conversation_id', $upstreamId)
            ->first();

        $title = is_string($item['title'] ?? null) ? $item['title'] : 'New chat';
        $updated = isset($item['update_time']) ? \Illuminate\Support\Carbon::parse($item['update_time']) : now();

        if ($existing) {
            $existing->update([
                'upstream_conversation_id' => $upstreamId,
                'title' => $title,
                'last_active_at' => $updated,
            ]);
            return;
        }

        MirrorConversation::create([
            'public_id' => (string) Str::uuid(),
            'conversation_id' => $upstreamId,
            'upstream_conversation_id' => $upstreamId,
            'account_id' => $accountId,
            'model' => 'auto',
            'gizmo_id' => $item['gizmo_id'] ?? null,
            'title' => $title,
            'initialized' => true,
            'last_active_at' => $updated,
        ]);
    }

    /**
     * @param array<string, mixed> $remote
     */
    protected function importRemoteConversationTree(MirrorConversation $conv, array $remote): void
    {
        $mapping = is_array($remote['mapping'] ?? null) ? $remote['mapping'] : [];
        $currentNode = isset($remote['current_node']) ? (string) $remote['current_node'] : null;
        if ($mapping === [] || !$currentNode) {
            return;
        }

        // Walk ancestors from current_node back to root
        $chain = [];
        $cursor = $currentNode;
        $visited = [];
        while ($cursor && isset($mapping[$cursor]) && !isset($visited[$cursor])) {
            $visited[$cursor] = true;
            $chain[] = $mapping[$cursor];
            $cursor = $mapping[$cursor]['parent'] ?? null;
        }
        $chain = array_reverse($chain);

        $publicId = $conv->public_id ?: (string) $conv->id;
        foreach ($chain as $node) {
            $msg = is_array($node['message'] ?? null) ? $node['message'] : null;
            if (!$msg) {
                continue;
            }
            $role = (string) ($msg['author']['role'] ?? '');
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $parts = is_array($msg['content']['parts'] ?? null) ? $msg['content']['parts'] : [];
            $text = implode("\n", array_filter($parts, 'is_string'));
            if (trim($text) === '') {
                continue;
            }

            MirrorMessage::updateOrCreate(
                ['id' => (string) Str::uuid()],
                [
                    'conversation_public_id' => $publicId,
                    'upstream_node_id' => (string) ($msg['id'] ?? $node['id']),
                    'role' => $role,
                    'content' => $text,
                    'status' => 'done',
                    'events_payload' => [],
                    'attachments_payload' => [],
                ]
            );
        }

        $conv->update([
            'title' => is_string($remote['title'] ?? null) ? $remote['title'] : $conv->title,
            'current_node_id' => $currentNode,
            'initialized' => true,
        ]);
    }
}
