<?php

declare(strict_types=1);

namespace Refatbd\LaravelChatGptMirror\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class MirrorToken extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'access_token_expires_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'last_used_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'active_requests' => 'integer',
        'total_requests' => 'integer',
        'total_tokens_used' => 'integer',
        'consecutive_errors' => 'integer',
        'meta' => 'array',
    ];

    public function getTable()
    {
        return config('mirror.storage.table', parent::getTable());
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(MirrorConversation::class, 'token_id');
    }

    /**
     * Mutator: Encrypt session token before persisting.
     */
    public function setSessionTokenAttribute(string $value): void
    {
        $this->attributes['session_token'] = Crypt::encryptString($value);
    }

    /**
     * Accessor: Decrypt session token.
     */
    public function getSessionTokenAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value; // Fallback in case raw plaintext token was passed
        }
    }

    /**
     * Mutator: Encrypt access token before persisting.
     */
    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value !== null ? Crypt::encryptString($value) : null;
    }

    /**
     * Accessor: Decrypt access token.
     */
    public function getAccessTokenAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Check if the token is eligible to receive incoming traffic.
     */
    public function isHealthy(): bool
    {
        if ($this->status === 'disabled' || $this->status === 'expired') {
            return false;
        }

        if ($this->status === 'cooldown') {
            if ($this->cooldown_until && $this->cooldown_until->isFuture()) {
                return false;
            }
            // Cooldown has elapsed, restore to active
            $this->update([
                'status' => 'active',
                'cooldown_until' => null,
                'consecutive_errors' => 0,
            ]);
        }

        return true;
    }

    /**
     * Check if cached access token needs a refresh from chatgpt.com/api/auth/session.
     */
    public function needsRefresh(): bool
    {
        if (empty($this->access_token) || $this->access_token_expires_at === null) {
            return true;
        }

        // Refresh 2 minutes before actual expiration
        return Carbon::now()->addMinutes(2)->gte($this->access_token_expires_at);
    }

    /**
     * Increment active request counter atomically.
     */
    public function incrementActive(): void
    {
        $this->increment('active_requests');
        $this->update(['last_used_at' => Carbon::now()]);
    }

    /**
     * Decrement active request counter and record metrics.
     */
    public function decrementActive(bool $success = true, ?int $tokensUsed = null): void
    {
        $decrement = $this->active_requests > 0 ? 1 : 0;
        
        $updates = [
            'total_requests' => $this->total_requests + 1,
        ];

        if ($decrement > 0) {
            $updates['active_requests'] = $this->active_requests - 1;
        }

        if ($success) {
            $updates['consecutive_errors'] = 0;
            if ($tokensUsed) {
                $updates['total_tokens_used'] = $this->total_tokens_used + $tokensUsed;
            }
        } else {
            $updates['consecutive_errors'] = $this->consecutive_errors + 1;
        }

        $this->update($updates);
    }

    /**
     * Put token into cooldown mode.
     */
    public function enterCooldown(int $seconds = 60): void
    {
        $this->update([
            'status' => 'cooldown',
            'cooldown_until' => Carbon::now()->addSeconds($seconds),
            'active_requests' => max(0, $this->active_requests - 1),
            'consecutive_errors' => $this->consecutive_errors + 1,
        ]);
    }
}
