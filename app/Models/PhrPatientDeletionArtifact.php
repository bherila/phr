<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhrPatientDeletionArtifact extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'deletion_id',
        'storage_disk',
        'storage_key',
        'storage_key_hash',
        'expected_bytes',
        'attempt_count',
        'status',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'deletion_id' => 'integer',
            'expected_bytes' => 'integer',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhrPatientDeletion, $this> */
    public function deletion(): BelongsTo
    {
        return $this->belongsTo(PhrPatientDeletion::class, 'deletion_id');
    }
}
