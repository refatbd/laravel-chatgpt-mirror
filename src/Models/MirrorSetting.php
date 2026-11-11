<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key-value settings store (account instructions, hotkeys, sync cursors, asset link generation, diagnostics).
 */
class MirrorSetting extends Model
{
    protected $table = 'mirror_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        try {
            $record = self::find($key);
            return $record ? $record->value : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function setValue(string $key, ?string $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function getJson(string $key, array $default = []): array
    {
        $raw = self::getValue($key);
        if (!$raw) {
            return $default;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function setJson(string $key, array $value): void
    {
        self::setValue($key, json_encode($value));
    }
}
