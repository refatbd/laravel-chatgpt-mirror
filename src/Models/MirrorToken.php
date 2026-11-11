<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Represents a single ChatGPT web account in the multi-account token pool.
 */
class MirrorToken extends Model
{
    protected $table = 'mirror_tokens';

    public function getTable(): string
    {
        return (string) config('mirror.storage.tokens_table', config('mirror.storage.table', $this->table));
    }

    protected $fillable = [
        'name',
        'email',
        'session_token',
        'token_hash',
        'access_token',
        'access_token_expires_at',
        'plan_type',
        'account_id',
        'status',
        'active_requests',
        'total_requests',
        'total_tokens_used',
        'consecutive_errors',
        'cooldown_until',
        'last_used_at',
        'last_refreshed_at',
        'last_error',
        'meta',
    ];

    protected $casts = [
        'access_token_expires_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'last_used_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'active_requests' => 'integer',
        'total_requests' => 'integer',
        'total_tokens_used' => 'integer',
        'consecutive_errors' => 'integer',
    ];

    protected $hidden = [
        'session_token',
        'access_token',
        'meta',
    ];

    public function setSessionTokenAttribute(?string $value): void
    {
        $this->attributes['session_token'] = $value !== null && $value !== '' ? Crypt::encryptString($value) : null;
        if ($value !== null && $value !== '') {
            $this->attributes['token_hash'] = hash('sha256', trim($value));
        }
    }

    public function getSessionTokenAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value !== null && $value !== '' ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    public function setMetaAttribute(mixed $value): void
    {
        if ($value === null || $value === []) {
            $this->attributes['meta'] = null;
            return;
        }

        $json = is_string($value) ? $value : json_encode($value);
        $this->attributes['meta'] = Crypt::encryptString((string) $json);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetaAttribute(?string $value): array
    {
        if (empty($value)) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
    }

    public function getDeviceId(): string
    {
        $meta = $this->meta;
        if (!empty($meta['device_id']) && is_string($meta['device_id'])) {
            return $meta['device_id'];
        }

        $deviceId = (string) Str::uuid();
        $meta['device_id'] = $deviceId;
        $this->meta = $meta;
        if ($this->exists) {
            $this->saveQuietly();
        }

        return $deviceId;
    }

    public function getTurnstileToken(): ?string
    {
        $meta = $this->meta;
        $token = $meta['turnstile_token'] ?? null;
        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    public function setTurnstileToken(?string $token): void
    {
        $meta = $this->meta;
        if ($token !== null && trim($token) !== '') {
            $meta['turnstile_token'] = trim($token);
            $meta['turnstile_token_saved_at'] = now()->timestamp;
        } else {
            unset($meta['turnstile_token'], $meta['turnstile_token_saved_at']);
        }
        $this->meta = $meta;
    }

    public function getEffectiveAccountId(): string
    {
        $id = $this->account_id;
        return is_string($id) && trim($id) !== '' ? trim($id) : 'default';
    }

    public function isHealthy(): bool
    {
        if ($this->status === 'disabled' || $this->status === 'expired') {
            return false;
        }

        if ($this->cooldown_until && $this->cooldown_until->isFuture()) {
            return false;
        }

        return !empty($this->session_token) || !empty($this->access_token);
    }

    public function needsRefresh(): bool
    {
        if (empty($this->access_token)) {
            return true;
        }

        if ($this->access_token_expires_at === null) {
            $jwtExp = self::decodeJwtExpiry($this->access_token);
            if ($jwtExp === null) {
                return false;
            }
            return ($jwtExp - time()) <= (int) config('mirror.pool.refresh_buffer_seconds', 300);
        }

        $buffer = (int) config('mirror.pool.refresh_buffer_seconds', 300);
        return $this->access_token_expires_at->subSeconds($buffer)->isPast();
    }

    public static function decodeJwtExpiry(?string $jwt): ?int
    {
        if (!$jwt || !str_contains($jwt, '.')) {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['exp']) || !is_numeric($decoded['exp'])) {
            return null;
        }

        return (int) $decoded['exp'];
    }

    /**
     * Atomically claim one in-flight concurrency slot only if healthy & below maxConcurrent.
     * Prevents multi-worker PHP-FPM / Octane race conditions exceeding max_concurrent_per_token.
     */
    public function tryAcquireSlot(int $maxConcurrent): bool
    {
        $now = Carbon::now();

        $affected = static::whereKey($this->getKey())
            ->whereIn('status', ['active', 'cooldown'])
            ->where(function ($q) use ($now): void {
                $q->whereNull('cooldown_until')
                    ->orWhere('cooldown_until', '<=', $now);
            })
            ->where('active_requests', '<', $maxConcurrent)
            ->update([
                'active_requests' => DB::raw('active_requests + 1'),
                'status' => 'active',
                'cooldown_until' => null,
                'last_used_at' => $now,
            ]);

        if ($affected !== 1) {
            return false;
        }

        $this->refresh();
        return true;
    }

    public function incrementActive(): void
    {
        static::whereKey($this->getKey())->update([
            'active_requests' => DB::raw('active_requests + 1'),
            'last_used_at' => Carbon::now(),
        ]);
        $this->refresh();
    }

    public function decrementActive(bool $success = true, ?int $tokensUsed = null): void
    {
        $updates = [
            'active_requests' => DB::raw('CASE WHEN active_requests > 0 THEN active_requests - 1 ELSE 0 END'),
            'total_requests' => DB::raw('total_requests + 1'),
        ];

        if ($success) {
            $updates['consecutive_errors'] = 0;
            $updates['last_error'] = null;
            if ($tokensUsed && $tokensUsed > 0) {
                $updates['total_tokens_used'] = DB::raw('total_tokens_used + ' . (int) $tokensUsed);
            }
        } else {
            $updates['consecutive_errors'] = DB::raw('consecutive_errors + 1');
        }

        static::whereKey($this->getKey())->update($updates);
        $this->refresh();
    }

    public function enterCooldown(int $seconds = 60): void
    {
        static::whereKey($this->getKey())->update([
            'status' => 'cooldown',
            'cooldown_until' => Carbon::now()->addSeconds($seconds),
            'active_requests' => DB::raw('CASE WHEN active_requests > 0 THEN active_requests - 1 ELSE 0 END'),
            'consecutive_errors' => DB::raw('consecutive_errors + 1'),
        ]);
        $this->refresh();
    }
}
