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
 * @property string|null $import_source
 * @property string|null $external_id
 * @property int|null $source_document_id
 * @property string $substance
 * @property string|null $rxnorm_code
 * @property string|null $snomed_code
 * @property string|null $category
 * @property string|null $criticality
 * @property string $clinical_status
 * @property string $verification_status
 * @property string|null $reaction
 * @property string|null $severity
 * @property string|null $notes
 * @property string|null $raw_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrAllergy extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'substance',
        'rxnorm_code',
        'snomed_code',
        'category',
        'criticality',
        'clinical_status',
        'verification_status',
        'reaction',
        'severity',
        'notes',
        'raw_text',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
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
}
