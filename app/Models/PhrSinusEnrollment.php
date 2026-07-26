<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Database\Factories\PhrSinusEnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Teach-mode training example: a derived YAMNet embedding plus its label.
 * Never audio — the device discards raw samples the moment the embedding is
 * computed.
 *
 * `client_enrollment_uuid` and `embedding` are raw binary (BINARY(16) and
 * VARBINARY). They must not be cast — a cast would mangle the bytes. The API
 * speaks base64 and decodes at the boundary.
 *
 * @property int $id
 * @property int $phr_patient_id
 * @property string $client_enrollment_uuid raw 16 bytes
 * @property string $class
 * @property bool $is_negative
 * @property bool $negative_scoped
 * @property string $embedding little-endian f32 bytes
 * @property int $embedding_dim
 * @property string|null $model_version
 * @property float|null $similarity
 * @property float|null $separation
 * @property float|null $peak_dbfs
 * @property string|null $source_event_uuid
 * @property string|null $device_id
 * @property Carbon $captured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrSinusEnrollment extends Model
{
    /** @use HasFactory<PhrSinusEnrollmentFactory> */
    use HasFactory;

    use SerializesDatesAsLocal;

    /** Width of the `embedding` VARBINARY column, in bytes. */
    public const MAX_EMBEDDING_BYTES = 16384;

    /** Bytes per embedding component (f32). */
    public const BYTES_PER_DIM = 4;

    /** Raw byte length of a uuid. */
    public const UUID_BYTES = 16;

    protected $table = 'phr_sinus_enrollments';

    protected $fillable = [
        'phr_patient_id',
        'client_enrollment_uuid',
        'class',
        'is_negative',
        'negative_scoped',
        'embedding',
        'embedding_dim',
        'model_version',
        'similarity',
        'separation',
        'peak_dbfs',
        'source_event_uuid',
        'device_id',
        'captured_at',
    ];

    /**
     * Note the absence of `client_enrollment_uuid` and `embedding`: they are
     * raw binary and any cast would corrupt them.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phr_patient_id' => 'integer',
            'is_negative' => 'boolean',
            'negative_scoped' => 'boolean',
            'embedding_dim' => 'integer',
            'similarity' => 'float',
            'separation' => 'float',
            'peak_dbfs' => 'float',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'phr_patient_id');
    }

    /**
     * @param  Builder<PhrSinusEnrollment>  $query
     * @return Builder<PhrSinusEnrollment>
     */
    public function scopeForPatient(Builder $query, int $patientId): Builder
    {
        return $query->where('phr_patient_id', $patientId);
    }
}
