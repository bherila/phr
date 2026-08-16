<?php

namespace App\Support\AgentApi;

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
        ksort($attributes);

        return hash_hmac(
            'sha256',
            json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $key,
        );
    }
}
