<?php

namespace App\Support\AgentApi;

use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AgentApiCursor
{
    public static function decode(?string $encoded): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), strict: true);
        try {
            $values = is_string($decoded)
                ? json_decode($decoded, true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            $values = null;
        }
        // These endpoints order on exactly one positive integer key. Laravel's
        // decoder accepts arbitrary JSON values, so validate the decoded shape
        // before it reaches query construction and turn hostile cursors into the
        // documented 422 response rather than a database or undefined-key error.
        if (
            ! is_array($values)
            || count($values) !== 2
            || ! array_key_exists('id', $values)
            || ! array_key_exists('_pointsToNextItems', $values)
        ) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        $id = $values['id'];
        $direction = $values['_pointsToNextItems'];
        if (! is_int($id) || $id < 1 || ! is_bool($direction)) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return new Cursor(['id' => $id], $direction);
    }
}
