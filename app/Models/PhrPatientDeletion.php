<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $actor_user_id
 * @property int $patient_root_id
 * @property string $preview_digest
 * @property array<string, int> $record_counts_json
 * @property int $active_share_count
 * @property int $artifact_count
 * @property int $artifact_bytes
 * @property string $status
 * @property string|null $failure_category
 * @property Carbon $deleted_at
 * @property Carbon|null $completed_at
 */
class PhrPatientDeletion extends Model
{
    public const string STATUS_PENDING = 'pending_cleanup';

    public const string STATUS_PROCESSING = 'cleanup_processing';

    public const string STATUS_FAILED = 'cleanup_failed';

    public const string STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'actor_user_id',
        'patient_root_id',
        'preview_digest',
        'record_counts_json',
        'active_share_count',
        'artifact_count',
        'artifact_bytes',
        'status',
        'failure_category',
        'deleted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'patient_root_id' => 'integer',
            'record_counts_json' => 'array',
            'active_share_count' => 'integer',
            'artifact_count' => 'integer',
            'artifact_bytes' => 'integer',
            'deleted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<PhrPatientDeletionArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(PhrPatientDeletionArtifact::class, 'deletion_id');
    }
}
