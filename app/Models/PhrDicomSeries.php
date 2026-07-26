<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $study_id
 * @property string $series_instance_uid
 * @property string|null $modality
 * @property int|null $series_number
 * @property string|null $description
 * @property string|null $body_part
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 * @property-read PhrDicomStudy $study
 * @property-read Collection<int, PhrDicomInstance> $instances
 */
class PhrDicomSeries extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'study_id',
        'series_instance_uid',
        'modality',
        'series_number',
        'description',
        'body_part',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'study_id' => 'integer',
            'series_number' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrDicomStudy, $this> */
    public function study(): BelongsTo
    {
        return $this->belongsTo(PhrDicomStudy::class, 'study_id');
    }

    /** @return HasMany<PhrDicomInstance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(PhrDicomInstance::class, 'series_id');
    }
}
