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
 * @property string $name
 * @property string|null $cpt_code
 * @property string|null $snomed_code
 * @property Carbon|null $performed_at
 * @property Carbon|null $performed_on
 * @property string|null $performer_name
 * @property string|null $performer_specialty
 * @property string|null $facility_name
 * @property string $status
 * @property string|null $reason
 * @property string|null $outcome
 * @property string|null $notes
 * @property string|null $raw_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrProcedure extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'name',
        'cpt_code',
        'snomed_code',
        'performed_at',
        'performed_on',
        'performer_name',
        'performer_specialty',
        'facility_name',
        'status',
        'reason',
        'outcome',
        'notes',
        'raw_text',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'performed_at' => 'datetime',
            'performed_on' => 'date',
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
