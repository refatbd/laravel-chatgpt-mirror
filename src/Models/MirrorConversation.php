<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Local mirror record of a ChatGPT conversation thread.
 */
class MirrorConversation extends Model
{
    protected $table = 'mirror_conversations';

    protected $fillable = [
        'public_id',
        'conversation_id',
        'upstream_conversation_id',
        'account_id',
        'token_id',
        'model',
        'gizmo_id',
        'is_private',
        'is_branch',
        'branched_from_id',
        'branched_from_message_id',
        'initialized',
        'init_payload',
        'instructions_payload',
        'openai_context_hash',
        'openai_transcript_hash',
        'current_node_id',
        'title',
        'messages_payload',
        'last_active_at',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'is_branch' => 'boolean',
        'initialized' => 'boolean',
        'init_payload' => 'array',
        'instructions_payload' => 'array',
        'messages_payload' => 'array',
        'last_active_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            if (empty($conversation->public_id)) {
                $conversation->public_id = (string) Str::uuid();
            }
            if (empty($conversation->conversation_id)) {
                $conversation->conversation_id = $conversation->upstream_conversation_id
                    ?: ('local:' . $conversation->public_id);
            }
            if (empty($conversation->account_id)) {
                $conversation->account_id = 'default';
            }
        });
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(MirrorToken::class, 'token_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MirrorMessage::class, 'conversation_public_id', 'public_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function effectiveUpstreamConversationId(): ?string
    {
        if (!empty($this->upstream_conversation_id)) {
            return $this->upstream_conversation_id;
        }

        if (!empty($this->conversation_id) && !str_starts_with($this->conversation_id, 'local:')) {
            return $this->conversation_id;
        }

        return null;
    }

    public static function findByIdentifier(string $identifier): ?self
    {
        return self::where('public_id', $identifier)
            ->orWhere('conversation_id', $identifier)
            ->orWhere('upstream_conversation_id', $identifier)
            ->when(ctype_digit($identifier), fn ($q) => $q->orWhere('id', (int) $identifier))
            ->first();
    }

    /**
     * Format matching Node.js / Playground StoredConversation contract.
     *
     * @return array<string, mixed>
     */
    public function toPlaygroundArray(): array
    {
        $publicId = $this->public_id ?: ($this->conversation_id ?: (string) $this->id);

        return [
            'id' => $publicId,
            'conversationId' => $this->effectiveUpstreamConversationId(),
            'currentNodeId' => $this->current_node_id,
            'model' => $this->model ?: 'auto',
            'gizmoId' => $this->gizmo_id,
            'title' => $this->title ?: 'New chat',
            'createdAt' => ($this->created_at ?? now())->toIso8601String(),
            'updatedAt' => ($this->last_active_at ?? $this->updated_at ?? now())->toIso8601String(),
            'initialized' => (bool) $this->initialized,
            'init' => $this->init_payload,
            'private' => (bool) $this->is_private,
            'isBranch' => (bool) $this->is_branch,
            'branchedFromId' => $this->branched_from_id,
            'branchedFromMessageId' => $this->branched_from_message_id,
            'accountId' => $this->account_id ?: 'default',
        ];
    }
}
