<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Preserved member bytes, content-addressed within one patient.
 *
 * @property int $id
 * @property int $patient_id
 * @property string $sha256
 * @property int $byte_size
 * @property string $storage_disk
 * @property string $storage_path
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 */
class PhrEhiBlob extends Model
{
    use SerializesDatesAsLocal;

    protected $table = 'phr_ehi_blobs';

    protected $fillable = [
        'patient_id',
        'sha256',
        'byte_size',
        'storage_disk',
        'storage_path',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }
}
