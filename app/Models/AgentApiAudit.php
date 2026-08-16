<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentApiAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'actor_user_id',
        'oauth_client_id',
        'oauth_token_hash',
        'event',
        'route_name',
        'http_method',
        'response_status',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
