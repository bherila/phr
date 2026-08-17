<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $patient_id
 * @property string $oauth_client_id
 * @property string $operation
 * @property string $external_id_hash
 * @property string $request_hash
 * @property string $target_table
 * @property int $target_id
 */
final class AgentApiMutationIdentity extends Model
{
    protected $fillable = [
        'patient_id',
        'oauth_client_id',
        'operation',
        'external_id_hash',
        'request_hash',
        'target_table',
        'target_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'target_id' => 'integer',
        ];
    }
}
