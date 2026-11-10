<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MirrorConversation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'messages_payload' => 'array',
        'last_active_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('mirror.storage.conversations_table', parent::getTable());
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(MirrorToken::class, 'token_id');
    }
}
