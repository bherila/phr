<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A browser upload of one EHI Tables ZIP: staged in chunks, then taken in by a
 * queued job through the same intake service as `phr:ehi:intake`.
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $actor_user_id
 * @property int|null $package_id
 * @property int|null $source_document_id
 * @property string $storage_disk
 * @property string|null $storage_path
 * @property int $file_size_bytes
 * @property int $uploaded_bytes
 * @property string $status
 * @property string|null $format
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $result_json
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PhrEhiPackage|null $package
 */
class PhrEhiUpload extends Model
{
    use SerializesDatesAsLocal;

    /** Chunks are being appended. */
    public const string STATUS_UPLOADING = 'uploading';

    /** Every byte arrived and the intake job is queued. */
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_PROCESSING = 'processing';

    /** Intake preserved and accounted the package (or replayed an identical one). */
    public const string STATUS_COMPLETED = 'completed';

    /** Refused before anything was stored: not EHI, unsafe, over a limit, or more than one patient. */
    public const string STATUS_REJECTED = 'rejected';

    /** Stopped after the package row existed, or could not run; intake can be queued again. */
    public const string STATUS_FAILED = 'failed';

    public const array TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_REJECTED];

    /** An Epic EHI Tables export: preserved, accounted, then previewed for review. */
    public const string FORMAT_EHI = 'ehi';

    /** A MyChart record ZIP: its FHIR and C-CDA files were imported directly, pending review. */
    public const string FORMAT_MYCHART = 'mychart';

    protected $table = 'phr_ehi_uploads';

    protected $fillable = [
        'patient_id',
        'actor_user_id',
        'package_id',
        'source_document_id',
        'storage_disk',
        'storage_path',
        'file_size_bytes',
        'uploaded_bytes',
        'status',
        'format',
        'failure_reason',
        'result_json',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'actor_user_id' => 'integer',
            'package_id' => 'integer',
            'source_document_id' => 'integer',
            'file_size_bytes' => 'integer',
            'uploaded_bytes' => 'integer',
            'result_json' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrEhiPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(PhrEhiPackage::class, 'package_id');
    }
}
