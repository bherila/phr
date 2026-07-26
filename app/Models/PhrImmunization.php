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
 * @property string $vaccine_name
 * @property string|null $cvx_code
 * @property string|null $manufacturer
 * @property string|null $lot_number
 * @property Carbon|null $administered_on
 * @property int|null $dose_number
 * @property int|null $series_doses
 * @property string|null $site
 * @property string|null $route
 * @property string|null $administered_by
 * @property string|null $facility_name
 * @property string|null $notes
 * @property string|null $raw_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrImmunization extends Model
{
    use SerializesDatesAsLocal;

    protected $fillable = [
        'patient_id',
        'user_id',
        'import_source',
        'external_id',
        'source_document_id',
        'vaccine_name',
        'cvx_code',
        'manufacturer',
        'lot_number',
        'administered_on',
        'dose_number',
        'series_doses',
        'site',
        'route',
        'administered_by',
        'facility_name',
        'notes',
        'raw_text',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'user_id' => 'integer',
            'source_document_id' => 'integer',
            'administered_on' => 'date',
            'dose_number' => 'integer',
            'series_doses' => 'integer',
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
