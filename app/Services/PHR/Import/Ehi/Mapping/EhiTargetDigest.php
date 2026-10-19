<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use Illuminate\Database\Eloquent\Model;

/**
 * Digest of a clinical record's stored attributes, captured at preview and
 * re-checked at apply so a proposal never lands on a record that changed since
 * the reviewer saw it. Timestamps are included: any write counts as a change.
 */
final class EhiTargetDigest
{
    public static function of(Model $record): string
    {
        $attributes = $record->getAttributes();
        ksort($attributes, SORT_STRING);
        $normalized = array_map(
            static fn (mixed $value): ?string => $value === null ? null : (string) $value,
            $attributes,
        );

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
