<?php

namespace App\Support\AgentApi;

use Illuminate\Validation\ValidationException;
use JsonException;

final class AgentRecordCursor
{
    /** @return array{event_at: string, resource_type: string, id: int}|null */
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
            || array_keys($value) !== ['event_at', 'resource_type', 'id']
            || ! is_string($value['event_at'])
            || preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)?$/D', $value['event_at']) !== 1
            || ! in_array($value['resource_type'], AgentRecordSearchCatalog::ids(), true)
            || ! is_int($value['id'])
            || $value['id'] < 1
        ) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return $value;
    }

    /** @param array{event_at: string, resource_type: string, id: int} $value */
    public static function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
