<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property string|null $import_source
 * @property string|null $external_id
 * @property int|null $source_document_id
 * @property string|null $vital_name
 * @property Carbon|null $vital_date
 * @property Carbon|null $observed_at
 * @property string|null $vital_value
 * @property string|null $value_numeric
 * @property string|null $value_numeric_secondary
 * @property string|null $unit
 * @property string|null $secondary_unit
 * @property string|null $body_site
 * @property string|null $source
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrPatientVital extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_patient_vitals';

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'vital_name',
        'vital_date',
        'observed_at',
        'vital_value',
        'value_numeric',
        'value_numeric_secondary',
        'unit',
        'secondary_unit',
        'body_site',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'vital_date' => 'date',
            'observed_at' => 'datetime',
            'value_numeric' => 'decimal:10',
            'value_numeric_secondary' => 'decimal:10',
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

    /** @return BelongsTo<PhrDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(PhrDocument::class, 'source_document_id');
    }

    /**
     * @param  Builder<PhrPatientVital>  $query
     * @return Builder<PhrPatientVital>
     */
    public function scopeForPatient(Builder $query, int $patientId): Builder
    {
        return $query->where('patient_id', $patientId);
    }

    /**
     * @param  Builder<PhrPatientVital>  $query
     * @return Builder<PhrPatientVital>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
