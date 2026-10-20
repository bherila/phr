<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $consent_id
 * @property string $patient_public_id
 * @property int $client_consent_version
 * @property int $version
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 */
final class OAuthPatientConsent extends Model
{
    protected $table = 'oauth_patient_consents';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? config('passport.connection');
    }

    protected function casts(): array
    {
        return [
            'consent_id' => 'integer',
            'client_consent_version' => 'integer',
            'version' => 'integer',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
