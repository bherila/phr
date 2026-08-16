<?php

namespace App\Support\AgentApi;

use Illuminate\Validation\ValidationException;
use JsonException;

final class AgentEvidenceCursor
{
    private const array TARGET_TYPES = [
        'allergy', 'condition', 'document', 'eob', 'eob-line', 'immunization',
        'lab-result', 'medication', 'office-visit', 'procedure', 'vital',
    ];

    /** @return array{target_type: string, target_id: int}|null */
    public static function decode(?string $encoded): ?array
    {
        if ($encoded === null) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        try {
            $value = is_string($decoded) ? json_decode($decoded, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $value = null;
        }

        if (
            ! is_array($value)
            || array_keys($value) !== ['target_type', 'target_id']
            || ! is_string($value['target_type'])
            || ! in_array($value['target_type'], self::TARGET_TYPES, true)
            || ! is_int($value['target_id'])
            || $value['target_id'] < 1
        ) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return $value;
    }

    /** @param array{target_type: string, target_id: int} $value */
    public static function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
