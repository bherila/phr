<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OAuthTokenFamily extends Model
{
    protected $table = 'oauth_token_families';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }

    protected function casts(): array
    {
        return [
            'oauth_security_version' => 'integer',
            'revoked' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
