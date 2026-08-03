<?php

namespace App\Models;

use App\Traits\SerializesDatesAsLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $patient_id
 * @property int $requested_by_user_id
 * @property string $status
 * @property int $schema_version
 * @property string $storage_disk
 * @property string|null $storage_path
 * @property int|null $file_size_bytes
 * @property string|null $archive_sha256
 * @property array<string, int>|null $counts_json
 * @property string|null $failure_category
 * @property Carbon|null $generated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PhrNativeBackup extends Model
{
    use SerializesDatesAsLocal;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_READY = 'ready';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'patient_id',
        'requested_by_user_id',
        'status',
        'schema_version',
        'storage_disk',
        'storage_path',
        'file_size_bytes',
        'archive_sha256',
        'counts_json',
        'failure_category',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'schema_version' => 'integer',
            'file_size_bytes' => 'integer',
            'counts_json' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PhrPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
