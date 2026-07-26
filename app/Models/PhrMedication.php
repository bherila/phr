<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrMedicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
 * @property string|null $rxnorm_code
 * @property string|null $dose
 * @property string|null $dose_unit
 * @property string|null $route
 * @property string|null $frequency
 * @property Carbon|null $started_on
 * @property Carbon|null $ended_on
 * @property string $status
 * @property string|null $prescriber_name
 * @property string|null $reason_for_use
 * @property string|null $raw_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrMedication extends Model
{
    /** @use HasFactory<PhrMedicationFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'name',
        'rxnorm_code',
        'dose',
        'dose_unit',
        'route',
        'frequency',
        'started_on',
        'ended_on',
        'status',
        'prescriber_name',
        'reason_for_use',
        'raw_text',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'started_on' => 'date',
            'ended_on' => 'date',
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
