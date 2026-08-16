<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $actor_user_id
 * @property string $source_storage_disk
 * @property string|null $source_storage_path
 * @property int $source_file_size_bytes
 * @property int $uploaded_bytes
 * @property string|null $archive_sha256
 * @property int|null $schema_version
 * @property string|null $patient_native_id
 * @property int|null $target_patient_root_id
 * @property string|null $plan_digest
 * @property array<string, mixed>|null $plan_counts_json
 * @property int $access_grant_count
 * @property bool $restore_access_grants
 * @property string $status
 * @property string|null $failure_category
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 */
class PhrNativeRestoreAttempt extends Model
{
    public const string STATUS_PREVIEW_PENDING = 'preview_pending';

    public const string STATUS_UPLOADING = 'uploading';

    public const string STATUS_PREVIEW_PROCESSING = 'preview_processing';

    public const string STATUS_PREVIEW_READY = 'preview_ready';

    public const string STATUS_PENDING = 'pending_restore';

    public const string STATUS_PROCESSING = 'restore_processing';

    public const string STATUS_FINALIZING = 'restore_finalizing';

    public const string STATUS_FAILED = 'restore_failed';

    public const string STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'actor_user_id',
        'source_storage_disk',
        'source_storage_path',
        'source_file_size_bytes',
        'uploaded_bytes',
        'archive_sha256',
        'schema_version',
        'patient_native_id',
        'target_patient_root_id',
        'plan_digest',
        'plan_counts_json',
        'access_grant_count',
        'restore_access_grants',
        'status',
        'failure_category',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'source_file_size_bytes' => 'integer',
            'uploaded_bytes' => 'integer',
            'schema_version' => 'integer',
            'target_patient_root_id' => 'integer',
            'plan_counts_json' => 'array',
            'access_grant_count' => 'integer',
            'restore_access_grants' => 'boolean',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
