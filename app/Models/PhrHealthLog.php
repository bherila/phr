<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrHealthLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string $kind
 * @property string|null $description
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $entries_count
 * @property Carbon|null $latest_entry_at
 */
class PhrHealthLog extends Model
{
    /** @use HasFactory<PhrHealthLogFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;

    public const string KIND_MEAL = 'meal';

    public const string KIND_SNACK = 'snack';

    public const string KIND_SYMPTOM = 'symptom';

    public const string KIND_CUSTOM = 'custom';

    public const array KINDS = [
        self::KIND_MEAL,
        self::KIND_SNACK,
        self::KIND_SYMPTOM,
        self::KIND_CUSTOM,
    ];

    protected $fillable = [
        'patient_id',
        'user_id',
        'created_by_user_id',
        'name',
        'kind',
        'description',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'archived_at' => 'datetime',
            'latest_entry_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<PhrHealthLogEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(PhrHealthLogEntry::class, 'health_log_id');
    }
}
