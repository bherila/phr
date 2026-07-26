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
 * @property string|null $test_name
 * @property Carbon|null $collection_datetime
 * @property Carbon|null $result_datetime
 * @property string|null $result_status
 * @property string|null $ordering_provider
 * @property string|null $resulting_lab
 * @property string|null $analyte
 * @property string|null $value
 * @property string|null $value_numeric
 * @property string|null $unit
 * @property string|null $range_min
 * @property string|null $range_max
 * @property string|null $range_unit
 * @property string|null $reference_range_text
 * @property string|null $normal_value
 * @property string|null $abnormal_flag
 * @property string|null $message_from_provider
 * @property string|null $result_comment
 * @property string|null $lab_director
 * @property string|null $source
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrLabResult extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_lab_results';

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'test_name',
        'collection_datetime',
        'result_datetime',
        'result_status',
        'ordering_provider',
        'resulting_lab',
        'analyte',
        'value',
        'value_numeric',
        'unit',
        'range_min',
        'range_max',
        'range_unit',
        'reference_range_text',
        'normal_value',
        'abnormal_flag',
        'message_from_provider',
        'result_comment',
        'lab_director',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'collection_datetime' => 'datetime',
            'result_datetime' => 'datetime',
            'value_numeric' => 'decimal:10',
            'range_min' => 'decimal:10',
            'range_max' => 'decimal:10',
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
     * @param  Builder<PhrLabResult>  $query
     * @return Builder<PhrLabResult>
     */
    public function scopeForPatient(Builder $query, int $patientId): Builder
    {
        return $query->where('patient_id', $patientId);
    }

    /**
     * @param  Builder<PhrLabResult>  $query
     * @return Builder<PhrLabResult>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
