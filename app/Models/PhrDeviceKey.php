<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A per-device API key minted by DevicePairingExchangeController when a
 * one-time pairing code is redeemed.
 *
 * Guarded the same way User guards `mcp_api_key`: `token_hash` is deliberately
 * absent from $fillable, so it can only ever be written through issueFor(),
 * never through mass assignment.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property string $name
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class PhrDeviceKey extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'name',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh device key, replacing any existing key for the same
     * (user, device_id) pair — re-pairing a device is meant to invalidate
     * whatever key it had before. Callers that must keep this atomic with a
     * pairing-code consumption (DevicePairingExchangeController) should call
     * this from inside their own DB::transaction(); it does not open one of
     * its own.
     *
     * Returns the plaintext key, which is the only time it exists in a
     * readable form.
     *
     * @return array{key: self, plaintext: string}
     */
    public static function issueFor(User $user, string $deviceId, string $name, ?int $days = null): array
    {
        $plaintext = Str::random(64);

        static::query()
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->delete();

        $key = new self;
        $key->forceFill([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'name' => $name,
            'token_hash' => User::hashMcpToken($plaintext),
            'expires_at' => now()->addDays($days ?? User::MCP_TOKEN_DEFAULT_DAYS),
        ])->save();

        return ['key' => $key, 'plaintext' => $plaintext];
    }

    /**
     * A key with no recorded expiry is treated as inactive rather than
     * eternal, so the credential fails closed — mirrors User::mcpTokenIsActive().
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Record that the key authenticated a request, at most once a minute so
     * a batching device does not cause a write per call. Mirrors
     * User::recordMcpTokenUse().
     */
    public function recordUse(): void
    {
        $lastUsed = $this->last_used_at;

        if ($lastUsed !== null && $lastUsed->diffInSeconds(now(), absolute: true) < 60) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
