<?php

namespace App\Support\AgentApi;

use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;

final class AgentApiCursor
{
    public static function decode(?string $encoded): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);
        $values = $cursor?->toArray();
        $id = $values['id'] ?? null;
        $direction = $values['_pointsToNextItems'] ?? null;

        // These endpoints order on exactly one positive integer key. Laravel's
        // decoder accepts arbitrary JSON values, so validate the decoded shape
        // before it reaches query construction and turn hostile cursors into the
        // documented 422 response rather than a database or undefined-key error.
        if (
            $cursor === null
            || array_keys($values) !== ['id', '_pointsToNextItems']
            || ! is_int($id)
            || $id < 1
            || ! is_bool($direction)
        ) {
            throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
        }

        return $cursor;
    }
}
