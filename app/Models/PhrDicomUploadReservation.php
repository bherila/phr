<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bytes a signed URL allows at one DICOM staging key. A store accepts what a URL allows whether
 * or not the application is ever told, so these count against storage limits from the moment the
 * URL exists until the file is recorded, or its URL has expired and its bytes are gone.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $upload_id
 * @property string $staging_key
 * @property int $byte_size
 * @property Carbon $expires_at
 * @property Carbon|null $closed_at
 * @property-read PhrDicomUpload|null $upload
 */
class PhrDicomUploadReservation extends Model
{
    protected $table = 'phr_dicom_upload_reservations';

    protected $fillable = ['patient_id', 'upload_id', 'staging_key', 'byte_size', 'expires_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'upload_id' => 'integer',
            'byte_size' => 'integer',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrDicomUpload, $this> */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(PhrDicomUpload::class, 'upload_id');
    }
}
