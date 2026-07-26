<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property string $access_level
 * @property int|null $granted_by_user_id
 * @property Carbon|null $granted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class PhrPatientUserAccess extends Model
{
    use SerializesDatesAsLocal;

    public const string LEVEL_OWNER = 'owner';

    public const string LEVEL_MANAGER = 'manager';

    public const string LEVEL_VIEWER = 'viewer';

    public const array LEVELS = [
        self::LEVEL_OWNER,
        self::LEVEL_MANAGER,
        self::LEVEL_VIEWER,
    ];

    protected $table = 'phr_patient_user_access';

    protected $fillable = [
        'patient_id',
        'user_id',
        'access_level',
        'granted_by_user_id',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'granted_by_user_id' => 'integer',
            'granted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
