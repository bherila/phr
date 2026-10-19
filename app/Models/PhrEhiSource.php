<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A source installation and source patient, declared by a person at binding.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $created_by_user_id
 * @property string $label
 * @property string $source_patient_key
 * @property string|null $dictionary_fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 */
class PhrEhiSource extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_ehi_sources';

    protected $fillable = [
        'patient_id',
        'created_by_user_id',
        'label',
        'source_patient_key',
        'dictionary_fingerprint',
    ];

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return HasMany<PhrEhiPackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(PhrEhiPackage::class, 'source_id');
    }
}
