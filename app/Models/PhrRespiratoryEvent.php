<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrRespiratoryEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $phr_patient_id
 * @property string $client_event_uuid
 * @property string $event_type
 * @property Carbon $occurred_at
 * @property int $tz_offset_min
 * @property int|null $duration_ms
 * @property float|null $confidence
 * @property int $burst_count
 * @property float|null $peak_dbfs
 * @property float|null $mean_dbfs
 * @property float|null $noise_floor_dbfs
 * @property string|null $source
 * @property string|null $device_id
 * @property string|null $model_version
 * @property Carbon|null $false_positive_at
 * @property string|null $corrected_to_event_type
 * @property Carbon|null $corrected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrRespiratoryEvent extends Model
{
    /** @use HasFactory<PhrRespiratoryEventFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;

    /**
     * Detection taxonomy accepted from the on-device classifier.
     *
     * @var list<string>
     */
    public const EVENT_TYPES = [
        'cough',
        'throat_clearing',
        'sniffle',
        'sneeze',
        'nose_blow',
        'hawk',
        'snort_suck',
    ];

    /**
     * Recognised event sources (device platform).
     *
     * @var list<string>
     */
    public const SOURCES = [
        'desktop-mac',
        'desktop-win',
        'mobile-ios',
        'mobile-android',
    ];

    protected $table = 'phr_respiratory_events';

    protected $fillable = [
        'phr_patient_id',
        'client_event_uuid',
        'event_type',
        'occurred_at',
        'tz_offset_min',
        'duration_ms',
        'confidence',
        'burst_count',
        'peak_dbfs',
        'mean_dbfs',
        'noise_floor_dbfs',
        'source',
        'device_id',
        'model_version',
        'false_positive_at',
        'corrected_to_event_type',
        'corrected_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phr_patient_id' => 'integer',
            'occurred_at' => 'datetime',
            'tz_offset_min' => 'integer',
            'duration_ms' => 'integer',
            'confidence' => 'float',
            'burst_count' => 'integer',
            'peak_dbfs' => 'float',
            'mean_dbfs' => 'float',
            'noise_floor_dbfs' => 'float',
            'false_positive_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    /**
     * The label this event should be counted under.
     *
     * A false positive is a misdetection and is excluded from counts entirely.
     * A *correction* is different: the sound really happened, the classifier
     * just labelled it wrong, so it keeps counting — under the corrected label.
     * Counting a corrected event under the class the user explicitly told us was
     * wrong would be the worst of both.
     */
    public function effectiveEventType(): string
    {
        return $this->corrected_to_event_type ?? $this->event_type;
    }

    /**
     * Exclude known misdetections. Reads default to this; pass
     * `?include_false_positives=1` to audit them.
     *
     * @param  Builder<PhrRespiratoryEvent>  $query
     * @return Builder<PhrRespiratoryEvent>
     */
    public function scopeExcludingFalsePositives(Builder $query): Builder
    {
        return $query->whereNull('false_positive_at');
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'phr_patient_id');
    }

    /**
     * @param  Builder<PhrRespiratoryEvent>  $query
     * @return Builder<PhrRespiratoryEvent>
     */
    public function scopeForPatient(Builder $query, int $patientId): Builder
    {
        return $query->where('phr_patient_id', $patientId);
    }
}
