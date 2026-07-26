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
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SerializesDatesAsLocal;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'gemini_api_key',
        'mcp_api_key',
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
     * @var array
     */
    protected $appends = ['user_role', 'virtual_user_role'];

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
        ];
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
