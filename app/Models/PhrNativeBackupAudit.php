<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhrNativeBackupAudit extends Model
{
    protected $fillable = [
        'actor_user_id',
        'patient_root_id',
        'operation',
        'schema_version',
        'archive_sha256',
        'counts_json',
        'outcome',
        'failure_category',
    ];

    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'patient_root_id' => 'integer',
            'schema_version' => 'integer',
            'counts_json' => 'array',
        ];
    }
}
