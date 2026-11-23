<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrHealthLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string $kind
 * @property string|null $description
 * @property Carbon|null $archived_at
 * @property bool|null $name_reserved
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $entries_count
 * @property Carbon|null $latest_entry_at
 *
 * A deleted log is soft-deleted (#444), and every entry still in it with it, at the
 * same moment, so changes.list reports a tombstone for each. Its name is free again
 * at once: the unique index is (patient_id, name, name_reserved), and name_reserved,
 * true while the log stands, is cleared when it is deleted (NULLs never collide in a
 * unique index on any supported database).
 */
class PhrHealthLog extends Model
{
    /** @use HasFactory<PhrHealthLogFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;
    use SoftDeletes;

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

    protected static function booted(): void
    {
        static::softDeleted(static function (self $log): void {
            DB::table('phr_health_logs')->where('id', $log->id)->update(['name_reserved' => null]);
            $log->forceFill(['name_reserved' => null])->syncOriginalAttribute('name_reserved');
            PhrHealthLogEntry::query()->where('health_log_id', $log->id)
                ->update(['deleted_at' => $log->deleted_at, 'updated_at' => $log->deleted_at]);
        });
        static::restoring(static function (self $log): void {
            $log->name_reserved = true;
        });
    }

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'archived_at' => 'datetime',
            'latest_entry_at' => 'datetime',
            'deleted_at' => 'datetime',
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
