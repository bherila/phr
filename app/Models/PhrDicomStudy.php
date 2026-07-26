<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int|null $upload_id
 * @property string $study_instance_uid
 * @property Carbon|null $study_date
 * @property string|null $study_time
 * @property string|null $accession_number
 * @property string|null $description
 * @property string|null $modalities
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 * @property-read PhrDicomUpload|null $upload
 * @property-read Collection<int, PhrDicomSeries> $series
 * @property-read Collection<int, PhrDicomInstance> $instances
 */
class PhrDicomStudy extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'upload_id',
        'study_instance_uid',
        'study_date',
        'study_time',
        'accession_number',
        'description',
        'modalities',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'upload_id' => 'integer',
            'study_date' => 'date',
            'metadata_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrDicomUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PhrDicomUpload::class, 'upload_id');
    }

    /** @return HasMany<PhrDicomSeries, $this> */
    public function series(): HasMany
    {
        return $this->hasMany(PhrDicomSeries::class, 'study_id');
    }

    /** @return HasMany<PhrDicomInstance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(PhrDicomInstance::class, 'study_id');
    }

    /**
     * @param  Builder<PhrDicomStudy>  $query
     * @return Builder<PhrDicomStudy>
     */
    public function scopeForPatient(Builder $query, int $patientId): Builder
    {
        return $query->where('patient_id', $patientId);
    }
}
