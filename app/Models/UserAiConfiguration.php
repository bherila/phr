<?php

namespace App\Models;

use App\GenAiProcessor\Models\GenAiImportJob;
use Database\Factories\UserAiConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expires_at
 * @property Carbon|null $api_key_invalid_at
 */
class UserAiConfiguration extends Model
{
    /** @use HasFactory<UserAiConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'provider',
        'api_key',
        'region',
        'session_token',
        'model',
        'is_active',
        'expires_at',
        'api_key_invalid_at',
        'api_key_invalid_reason',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'session_token' => 'encrypted',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'api_key_invalid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<GenAiImportJob, $this> */
    public function importJobs(): HasMany
    {
        return $this->hasMany(GenAiImportJob::class, 'ai_configuration_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasInvalidApiKey(): bool
    {
        return $this->api_key_invalid_at !== null;
    }

    public function markApiKeyInvalid(string $reason): void
    {
        $this->update([
            'api_key_invalid_at' => now(),
            'api_key_invalid_reason' => $reason,
        ]);
    }

    public function clearApiKeyInvalid(): void
    {
        $this->update([
            'api_key_invalid_at' => null,
            'api_key_invalid_reason' => null,
        ]);
    }

    /**
     * @return array{this_month: array{input_tokens: int, output_tokens: int}, total: array{input_tokens: int, output_tokens: int}}
     */
    public function usageStats(): array
    {
        $thisMonth = $this->importJobs()
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output')
            ->first();

        $total = $this->importJobs()
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output')
            ->first();

        return [
            'this_month' => [
                'input_tokens' => (int) ($thisMonth->total_input ?? 0),
                'output_tokens' => (int) ($thisMonth->total_output ?? 0),
            ],
            'total' => [
                'input_tokens' => (int) ($total->total_input ?? 0),
                'output_tokens' => (int) ($total->total_output ?? 0),
            ],
        ];
    }

    /**
     * @param  array{this_month: array{input_tokens: int, output_tokens: int}, total: array{input_tokens: int, output_tokens: int}}|null  $precomputedUsage
     * @return array{id: int, name: string, provider: string, model: string, masked_key: string, region: string|null, is_active: bool, is_expired: bool, expires_at: string|null, has_invalid_api_key: bool, api_key_invalid_at: string|null, api_key_invalid_reason: string|null, created_at: string|null, usage: array{this_month: array{input_tokens: int, output_tokens: int}, total: array{input_tokens: int, output_tokens: int}}}
     */
    public function toApiArray(?array $precomputedUsage = null): array
    {
        $key = $this->api_key ?? '';
        $maskedKey = strlen($key) > 4 ? '••••'.substr($key, -4) : '••••';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'model' => $this->model,
            'masked_key' => $maskedKey,
            'region' => $this->region,
            'is_active' => $this->is_active,
            'is_expired' => $this->isExpired(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'has_invalid_api_key' => $this->hasInvalidApiKey(),
            'api_key_invalid_at' => $this->api_key_invalid_at?->toIso8601String(),
            'api_key_invalid_reason' => $this->api_key_invalid_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'usage' => $precomputedUsage ?? $this->usageStats(),
        ];
    }
}
