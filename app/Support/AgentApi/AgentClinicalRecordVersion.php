<?php

namespace App\Support\AgentApi;

use App\Models\PhrMedicationFill;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AgentClinicalRecordVersion
{
    public function for(Model $record): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('APP_KEY is required to version clinical records.');
        }

        $attributes = $record->getAttributes();
        // A fill's updated_at also moves when its claim links change, so sync clients see those
        // changes. Links aren't the fill's own columns, so they must not refuse a client's write.
        if ($record instanceof PhrMedicationFill) {
            unset($attributes['updated_at']);
        }
        ksort($attributes);

        return hash_hmac(
            'sha256',
            json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $key,
        );
    }
}
