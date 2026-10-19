<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A person's durable approval of one OAuth client for one protected resource.
 *
 * @property int $id
 * @property int $user_id
 * @property string $client_id
 * @property string $resource
 * @property list<string> $scopes
 * @property int $version
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 */
final class OAuthClientConsent extends Model
{
    protected $table = 'oauth_client_consents';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'scopes' => 'array',
            'version' => 'integer',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @param  list<string>  $scopes */
    public function covers(array $scopes): bool
    {
        return $this->revoked_at === null
            && array_diff($scopes, $this->scopes) === [];
    }
}
