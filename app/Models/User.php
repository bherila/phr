<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\SerializesDatesAsLocal;
use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $mcp_api_key_expires_at
 * @property Carbon|null $mcp_api_key_last_used_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SerializesDatesAsLocal;

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            // Data Hub audits retain operation proof but anonymize the actor when
            // a full account is removed. Neither table has clinical/free text.
            DB::table('phr_native_backup_audits')->where('actor_user_id', $user->id)->update(['actor_user_id' => null]);
            DB::table('phr_patient_deletions')->where('actor_user_id', $user->id)->update(['actor_user_id' => null]);
            if (Schema::hasTable('phr_native_restore_attempts')) {
                DB::table('phr_native_restore_attempts')->where('actor_user_id', $user->id)->update(['actor_user_id' => null]);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * `mcp_api_key` and its lifecycle columns are deliberately absent: the MCP
     * bearer token is a full-account credential and must only ever be written
     * through issueMcpToken()/revokeMcpToken(), never through mass assignment.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'gemini_api_key',
        'genai_daily_quota_limit',
        'user_role',
        'last_login_date',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mcp_api_key',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = ['virtual_user_role'];

    /**
     * Override the user_role attribute to return Admin for all admin-level users.
     */
    public function getUserRoleAttribute(): string
    {
        return $this->hasRole('admin') ? 'Admin' : ($this->attributes['user_role'] ?? 'User');
    }

    /**
     * Alias for user_role for backward compatibility.
     */
    public function getVirtualUserRoleAttribute(): string
    {
        return $this->user_role;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_date' => 'datetime',
            'genai_daily_quota_limit' => 'integer',
            'mcp_api_key_expires_at' => 'datetime',
            'mcp_api_key_last_used_at' => 'datetime',
        ];
    }

    /**
     * Default lifetime for a freshly issued MCP bearer token.
     */
    public const int MCP_TOKEN_DEFAULT_DAYS = 90;

    /**
     * Tokens are stored hashed so a database read does not yield a usable
     * credential. SHA-256 rather than a password hash is deliberate: the token
     * is 64 random characters, so it has no guessable structure to protect
     * against, and the lookup has to be a single indexed query.
     */
    public static function hashMcpToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issue a new MCP bearer token, invalidating any previous one.
     *
     * Returns the plaintext token, which is the only time it exists in a
     * readable form.
     */
    public function issueMcpToken(?int $days = null): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'mcp_api_key' => self::hashMcpToken($token),
            'mcp_api_key_expires_at' => now()->addDays($days ?? self::MCP_TOKEN_DEFAULT_DAYS),
            'mcp_api_key_last_used_at' => null,
        ])->save();

        return $token;
    }

    public function revokeMcpToken(): void
    {
        $this->forceFill([
            'mcp_api_key' => null,
            'mcp_api_key_expires_at' => null,
            'mcp_api_key_last_used_at' => null,
        ])->save();
    }

    /**
     * A token with no recorded expiry is treated as inactive rather than
     * eternal, so the credential fails closed.
     */
    public function mcpTokenIsActive(): bool
    {
        return $this->mcp_api_key !== null
            && $this->mcp_api_key_expires_at !== null
            && $this->mcp_api_key_expires_at->isFuture();
    }

    /**
     * Record that the token authenticated a request, at most once a minute so
     * a batching device does not cause a write per call.
     */
    public function recordMcpTokenUse(): void
    {
        $lastUsed = $this->mcp_api_key_last_used_at;

        if ($lastUsed !== null && $lastUsed->diffInSeconds(now(), absolute: true) < 60) {
            return;
        }

        $this->forceFill(['mcp_api_key_last_used_at' => now()])->saveQuietly();
    }

    public function getGeminiApiKey(): ?string
    {
        return $this->gemini_api_key;
    }

    /** @return HasMany<UserAiConfiguration, $this> */
    public function aiConfigurations(): HasMany
    {
        return $this->hasMany(UserAiConfiguration::class);
    }

    /** @return HasMany<PhrDeviceKey, $this> */
    public function deviceKeys(): HasMany
    {
        return $this->hasMany(PhrDeviceKey::class);
    }

    /** @return HasMany<PhrPatient, $this> */
    public function ownedPhrPatients(): HasMany
    {
        return $this->hasMany(PhrPatient::class, 'owner_user_id');
    }

    /** @return BelongsToMany<PhrPatient, $this> */
    public function accessiblePhrPatients(): BelongsToMany
    {
        return $this->belongsToMany(PhrPatient::class, 'phr_patient_user_access', 'user_id', 'patient_id')
            ->withPivot(['access_level', 'granted_by_user_id', 'granted_at'])
            ->withTimestamps();
    }

    public function activeAiConfiguration(): ?UserAiConfiguration
    {
        /** @var UserAiConfiguration|null */
        return $this->aiConfigurations()->where('is_active', true)->first();
    }

    public function resolvedAiClient(): ?GenAiClient
    {
        $config = $this->activeAiConfiguration();

        if ($config && ! $config->isExpired() && ! $config->hasInvalidApiKey()) {
            return match ($config->provider) {
                'gemini' => new GeminiClient(
                    apiKey: $config->api_key,
                    model: $config->model,
                    timeout: (int) config('genai.providers.gemini.timeout', 240),
                    responseMimeType: null,
                ),
                'anthropic' => new AnthropicClient(
                    apiKey: $config->api_key,
                    model: $config->model,
                    maxTokens: (int) config('genai.providers.anthropic.max_tokens', 64000),
                    timeout: (int) config('genai.providers.anthropic.timeout', 240),
                ),
                'bedrock' => new BedrockClient($config->api_key, $config->model, $config->region ?? 'us-east-1', $config->session_token ?? ''),
            };
        }

        if ($this->gemini_api_key) {
            return new GeminiClient(
                apiKey: $this->gemini_api_key,
                timeout: (int) config('genai.providers.gemini.timeout', 240),
                responseMimeType: null,
            );
        }

        return null;
    }

    /**
     * Check if user has a specific role.
     * Roles are stored as comma-separated lowercase strings in user_role column.
     */
    public function hasRole(string $role): bool
    {
        // User ID 1 always has admin role
        if ($role === 'admin' && $this->id === 1) {
            return true;
        }

        $rawRole = $this->attributes['user_role'] ?? '';

        if (empty($rawRole)) {
            return false;
        }

        $roles = array_map('trim', explode(',', strtolower($rawRole)));

        return in_array(strtolower($role), $roles, true);
    }

    /**
     * Get all roles as an array.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $rawRole = $this->attributes['user_role'] ?? '';

        if (empty($rawRole)) {
            return $this->id === 1 ? ['admin'] : [];
        }

        $roles = array_map('trim', explode(',', strtolower($rawRole)));

        // Ensure user ID 1 always has admin
        if ($this->id === 1 && ! in_array('admin', $roles, true)) {
            $roles[] = 'admin';
        }

        return array_values(array_unique($roles));
    }

    /**
     * Add a role to the user.
     */
    public function addRole(string $role): bool
    {
        $role = strtolower(trim($role));
        if (empty($role) || str_contains($role, ',')) {
            return false;
        }

        if ($this->hasRole($role)) {
            return true; // Already has role
        }

        $roles = $this->getRoles();
        $roles[] = $role;
        $this->user_role = implode(',', array_unique($roles));
        $this->save();

        return true;
    }

    /**
     * Remove a role from the user.
     * Cannot remove admin role from user ID 1.
     */
    public function removeRole(string $role): bool
    {
        $role = strtolower(trim($role));

        // Prevent removing admin from user ID 1
        if ($role === 'admin' && $this->id === 1) {
            return false;
        }

        $roles = $this->getRoles();
        $roles = array_filter($roles, fn ($r) => $r !== $role);
        $this->user_role = empty($roles) ? '' : implode(',', $roles);

        if (! $this->canLogin()) {
            $this->setRememberToken(Str::random(60));
        }

        $this->save();

        return true;
    }

    /**
     * Check if user can log in (has user or admin role).
     */
    public function canLogin(): bool
    {
        return $this->hasRole('user') || $this->hasRole('admin');
    }
}
