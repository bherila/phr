<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $upload_id
 * @property string $file_kind
 * @property string $r2_key
 * @property string $original_relative_path
 * @property string $original_path_hash
 * @property string $original_filename
 * @property string|null $mime_type
 * @property int $file_size_bytes
 * @property string $sha256
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrPatient $patient
 * @property-read PhrDicomUpload $upload
 * @property-read PhrDicomInstance|null $instance
 */
class PhrDicomFile extends Model
{
    use SerializesDatesAsLocal;

    public const KIND_DICOM = 'dicom';

    public const KIND_DICOMDIR = 'dicomdir';

    public const KIND_DERIVED_VOLUME = 'derived_volume';

    protected $fillable = [
        'patient_id',
        'upload_id',
        'file_kind',
        'r2_key',
        'original_relative_path',
        'original_path_hash',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'sha256',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'upload_id' => 'integer',
            'file_size_bytes' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<PhrDicomUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PhrDicomUpload::class, 'upload_id');
    }

    /** @return HasOne<PhrDicomInstance, $this> */
    public function instance(): HasOne
    {
        return $this->hasOne(PhrDicomInstance::class, 'file_id');
    }
}
