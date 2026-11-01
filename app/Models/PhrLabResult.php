<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $user_id
 * @property string|null $import_source
 * @property string|null $external_id
 * @property int|null $source_document_id
 * @property string $review_status
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
 * @property bool|null $range_min_inclusive
 * @property bool|null $range_max_inclusive
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
    use SoftDeletes;

    protected $table = 'phr_lab_results';

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'review_status',
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
        'range_min_inclusive',
        'range_max_inclusive',
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
            'retracted_at' => 'datetime',
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'collection_datetime' => 'datetime',
            'result_datetime' => 'datetime',
            'value_numeric' => 'decimal:10',
            'range_min' => 'decimal:10',
            'range_max' => 'decimal:10',
            'range_min_inclusive' => 'boolean',
            'range_max_inclusive' => 'boolean',
        ];
    }

    /**
     * Each reference bound with the attribute saying whether it includes its own
     * value. Null means the source did not say.
     */
    private const array RANGE_BOUNDS = [
        'range_min' => 'range_min_inclusive',
        'range_max' => 'range_max_inclusive',
    ];

    /**
     * A bound's inclusiveness describes that bound's value and no other. A write
     * that sets a different bound without saying whether it is inclusive leaves
     * it unknown, rather than carrying over what the source said of the old
     * value; a write that states both keeps what it states, changed or not.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        parent::fill($attributes);

        foreach (self::RANGE_BOUNDS as $bound => $inclusive) {
            if (array_key_exists($bound, $attributes)
                && ! array_key_exists($inclusive, $attributes)
                && $this->isDirty($bound)) {
                $this->setAttribute($inclusive, null);
            }
        }

        return $this;
    }

    protected static function booted(): void
    {
        // No bound, nothing for an inclusiveness to describe.
        static::saving(function (PhrLabResult $result): void {
            foreach (self::RANGE_BOUNDS as $bound => $inclusive) {
                if ($result->getAttribute($bound) === null) {
                    $result->setAttribute($inclusive, null);
                }
            }
        });
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
