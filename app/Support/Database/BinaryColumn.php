<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Raw bytes in a binary column. MariaDB and SQLite take and return them as plain
 * strings. PostgreSQL's bytea returns a stream and only accepts text in its hex
 * input format, so raw bytes pass through here on their way in and out.
 */
final class BinaryColumn
{
    public static function read(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_resource($value)) {
            return (string) $value;
        }

        rewind($value);

        return (string) stream_get_contents($value);
    }

    public static function bind(string $bytes): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? '\\x'.bin2hex($bytes) : $bytes;
    }
}
