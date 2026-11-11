<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Illuminate\Database\Eloquent\Model;
use Refatbd\LaravelChatGptMirror\Services\MirrorCache;

/**
 * Key-value settings store (account instructions, hotkeys, sync cursors, asset link generation, diagnostics).
 */
class MirrorSetting extends Model
{
    private const SENTINEL_NULL = '__MIRROR_NULL__';

    protected $table = 'mirror_settings';

    public function getTable(): string
    {
        return (string) config('mirror.storage.settings_table', $this->table);
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $model): void {
            self::invalidateCache((string) $model->key);
        });
        static::deleted(function (self $model): void {
            self::invalidateCache((string) $model->key);
        });
    }

    public static function getValue(string $key, ?string $default = null): ?string
    {
        try {
            $cache = self::cache();
            if ($cache !== null) {
                $cached = $cache->get('setting:' . $key);
                if ($cached === self::SENTINEL_NULL) {
                    return $default;
                }
                if (is_string($cached)) {
                    return $cached;
                }
            }

            $record = self::find($key);
            $val = $record ? $record->value : null;

            if ($cache !== null) {
                $ttl = (int) config('mirror.cache.settings_ttl', 300);
                $cache->put('setting:' . $key, $val ?? self::SENTINEL_NULL, $ttl);
            }

            return $val ?? $default;
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
        self::invalidateCache($key);
    }

    protected static function cache(): ?MirrorCache
    {
        try {
            if (function_exists('app') && app()->bound(MirrorCache::class)) {
                return app(MirrorCache::class);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    protected static function invalidateCache(string $key): void
    {
        self::cache()?->forget('setting:' . $key);
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
