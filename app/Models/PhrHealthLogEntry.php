<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrHealthLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $health_log_id
 * @property int $patient_id
 * @property int $user_id
 * @property int|null $recorded_by_user_id
 * @property Carbon $occurred_at
 * @property string|null $title
 * @property string|null $notes
 * @property int|null $intensity
 * @property array<int, string>|null $tags
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrHealthLogEntry extends Model
{
    /** @use HasFactory<PhrHealthLogEntryFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;

    /** @var list<string> */
    protected $touches = ['healthLog'];

    protected $fillable = [
        'health_log_id',
        'patient_id',
        'user_id',
        'recorded_by_user_id',
        'occurred_at',
        'title',
        'notes',
        'intensity',
        'tags',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'health_log_id' => 'integer',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'recorded_by_user_id' => 'integer',
            'occurred_at' => 'datetime',
            'intensity' => 'integer',
            'tags' => 'array',
            'details' => 'array',
        ];
    }

    /** @return BelongsTo<PhrHealthLog, $this> */
    public function healthLog(): BelongsTo
    {
        return $this->belongsTo(PhrHealthLog::class, 'health_log_id');
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
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
