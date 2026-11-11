<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted message turn inside a Mirror conversation.
 */
class MirrorMessage extends Model
{
    protected $table = 'mirror_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'conversation_public_id',
        'upstream_node_id',
        'role',
        'content',
        'status',
        'events_payload',
        'attachments_payload',
    ];

    protected $casts = [
        'events_payload' => 'array',
        'attachments_payload' => 'array',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toPlaygroundArray(): array
    {
        return [
            'id' => $this->id,
            'conversationId' => $this->conversation_public_id,
            'upstreamNodeId' => $this->upstream_node_id,
            'role' => $this->role,
            'content' => $this->content ?? '',
            'status' => $this->status ?: 'done',
            'events' => $this->events_payload ?? [],
            'attachments' => $this->attachments_payload ?? [],
            'createdAt' => ($this->created_at ?? now())->toIso8601String(),
        ];
    }
}
