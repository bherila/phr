<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A one-time device-pairing code minted by DevicePairingController::approve()
 * and redeemed by DevicePairingExchangeController.
 *
 * `code_hash` is guarded the same way `users.mcp_api_key` is: absent from
 * $fillable, so it can only ever be written through issueFor(). Storing the
 * hash rather than the code itself means a database read never yields a
 * usable code — the same rationale as User::hashMcpToken().
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property string $name
 * @property string $code_hash
 * @property string $code_challenge
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class PhrDevicePairingCode extends Model
{
    /**
     * The only redirect destination this feature will ever redirect to. A
     * custom URL scheme has none of the same-origin protections an https
     * redirect gets, so this is an exact allowlist rather than a pattern —
     * shared here so DevicePairingController and its FormRequests validate
     * against one literal value instead of three copies of it.
     */
    public const string ALLOWED_REDIRECT_URI = 'sinussentinel://paired';

    protected $fillable = [
        'user_id',
        'device_id',
        'name',
        'code_challenge',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'code_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mint a fresh pairing code for an approved device. Returns the plaintext
     * code, which is the only time it exists in a readable form — it is
     * handed to the browser once, as a redirect query parameter, and never
     * stored.
     *
     * @return array{code: self, plaintext: string}
     */
    public static function issueFor(User $user, string $deviceId, string $name, string $codeChallenge): array
    {
        $plaintext = Str::random(64);

        $code = new self;
        $code->forceFill([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'name' => $name,
            'code_challenge' => $codeChallenge,
            'code_hash' => User::hashMcpToken($plaintext),
            'expires_at' => now()->addMinutes(5),
        ])->save();

        return ['code' => $code, 'plaintext' => $plaintext];
    }

    /**
     * Fail closed: already-consumed or expired is not redeemable. There is no
     * "no expiry" case here (unlike PhrDeviceKey) — issueFor() always sets one.
     */
    public function isRedeemable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture();
    }
}
