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
 * @property Carbon|null $visit_date
 * @property Carbon|null $visit_started_at
 * @property Carbon|null $visit_ended_at
 * @property string|null $visit_type
 * @property string|null $provider_name
 * @property string|null $provider_specialty
 * @property string|null $facility_name
 * @property string|null $chief_complaint
 * @property string|null $assessment
 * @property string|null $plan
 * @property string|null $subjective
 * @property string|null $objective
 * @property array<int, array{code: string, description: string}>|null $icd10_codes
 * @property array<int, array{code: string, description: string}>|null $cpt_codes
 * @property string|null $raw_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrOfficeVisit extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'visit_date',
        'visit_started_at',
        'visit_ended_at',
        'visit_type',
        'provider_name',
        'provider_specialty',
        'facility_name',
        'chief_complaint',
        'assessment',
        'plan',
        'subjective',
        'objective',
        'icd10_codes',
        'cpt_codes',
        'raw_text',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'visit_date' => 'date',
            'visit_started_at' => 'datetime',
            'visit_ended_at' => 'datetime',
            'icd10_codes' => 'array',
            'cpt_codes' => 'array',
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
