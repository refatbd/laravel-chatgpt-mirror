<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks files uploaded through POST /api/files or resolved via multimodal attachments.
 */
class MirrorFile extends Model
{
    protected $table = 'mirror_files';

    protected $fillable = [
        'file_id',
        'account_id',
        'token_id',
        'file_name',
        'file_size',
        'mime_type',
        'use_case',
        'width',
        'height',
        'raw_payload',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'raw_payload' => 'array',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $payload = [
            'fileId' => $this->file_id,
            'fileName' => $this->file_name,
            'fileSize' => $this->file_size,
            'mimeType' => $this->mime_type,
            'useCase' => $this->use_case,
            'raw' => new \stdClass(),
        ];

        if ($this->width !== null) {
            $payload['width'] = $this->width;
        }
        if ($this->height !== null) {
            $payload['height'] = $this->height;
        }

        return $payload;
    }
}
