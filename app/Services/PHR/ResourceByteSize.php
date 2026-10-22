<?php

namespace App\Services\PHR;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SQL byte lengths, including on UTF-8 SQLite where length(text) counts characters. */
final class ResourceByteSize
{
    /** @var array<string, string> */
    private array $rows = [];

    /** SQL-only preflight; never hydrates the payload being measured. */
    public function row(string $table): string
    {
        $key = DB::connection()->getName().':'.$table;

        return $this->rows[$key] ??= implode(' + ', array_map(
            fn (string $column): string => 'COALESCE('.$this->column($column).', 0)',
            Schema::getColumnListing($table),
        ));
    }

    public function column(string $column): string
    {
        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'length(CAST('.$wrapped.' AS BLOB))',
            'sqlsrv' => 'DATALENGTH('.$wrapped.')',
            'pgsql' => 'OCTET_LENGTH(CAST('.$wrapped.' AS TEXT))',
            default => 'OCTET_LENGTH('.$wrapped.')',
        };
    }
}
