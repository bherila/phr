<?php

namespace App\Support\AgentApi;

use App\Models\PhrEob;
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
        if ($record instanceof PhrEob) {
            // Moving legacy snapshots to bounded history rows is representation
            // only. Retain a monotonic revision, including before backfill, so
            // A -> B -> A cannot make a stale EOB version current again.
            $parsed = isset($attributes['parsed_data']) ? json_decode($attributes['parsed_data'], true, flags: JSON_THROW_ON_ERROR) : null;
            $legacy = is_array($parsed) && is_array($parsed['corrections'] ?? null) ? count($parsed['corrections']) : 0;
            $attributes['correction_revision'] = max((int) ($attributes['correction_revision'] ?? 0), $legacy);
            if (is_array($parsed)) {
                unset($parsed['corrections']);
                $attributes['parsed_data'] = json_encode($parsed, JSON_THROW_ON_ERROR);
            }
        }
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
