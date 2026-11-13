<?php

namespace Tests\Support\Database;

use PDO;
use RuntimeException;

/** Compare fresh synthetic databases without putting row values in assertion logs. */
final class SqliteBootstrapSnapshot
{
    /**
     * @return array{migrations: list<string>, structure: array<string, array{table: string, tokens: list<string>}>, rows: array<string, array{count: int, sha256: string}>}
     */
    public static function capture(PDO $pdo): array
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('Bootstrap snapshots require SQLite.');
        }

        $objects = $pdo->query("SELECT type, name, tbl_name, sql FROM sqlite_schema WHERE substr(name, 1, 7) != 'sqlite_' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);
        $structure = [];
        $rows = [];
        $migrations = [];
        foreach ($objects as $object) {
            $name = $object['name'];
            $structure[$object['type'].':'.$name] = ['table' => $object['tbl_name'], 'tokens' => self::sqlTokens($object['sql'] ?? '')];
            if ($object['type'] !== 'table') {
                continue;
            }
            $quoted = '"'.str_replace('"', '""', $name).'"';
            if ($name === 'migrations') {
                // IDs and batch groupings describe execution order, not applied coverage.
                $migrations = $pdo->query('SELECT migration FROM '.$quoted.' ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);

                continue;
            }
            $rows[$name] = self::rowFingerprint($pdo->query('SELECT * FROM '.$quoted)->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($pdo->query("SELECT count(*) FROM sqlite_schema WHERE name = 'sqlite_sequence'")->fetchColumn() > 0) {
            $allocations = $pdo->query("SELECT name, seq FROM sqlite_sequence WHERE name != 'migrations'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allocations as $allocation) {
                if (! is_int($allocation['seq']) || $allocation['seq'] < 0) {
                    throw new RuntimeException('Invalid SQLite allocation state.');
                }
            }
            // Empty INSERT...SELECT rebuilds (e.g. the 2026_11_01 document-type
            // migration) leave seq=0. Dump bootstrap omits it; both next IDs are 1.
            // Keep every advanced counter, even when its table is now empty.
            $advanced = array_values(array_filter($allocations, static fn (array $row): bool => $row['seq'] !== 0));
            $rows['sqlite_sequence'] = self::rowFingerprint($advanced);
        }
        ksort($structure);
        ksort($rows);

        return ['migrations' => $migrations, 'structure' => $structure, 'rows' => $rows];
    }

    /** @return list<string> */
    private static function sqlTokens(string $sql): array
    {
        // Ignore layout/comments outside quoted values only. A space inside a SQL
        // literal, CHECK expression, index expression or trigger remains significant.
        preg_match_all('/\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*"|`(?:``|[^`])*`|\[[^\]]*\]|--[^\r\n]*|\/\*[\s\S]*?\*\/|[A-Za-z_][A-Za-z0-9_$]*|[^\s]/', $sql, $matches);

        return array_values(array_filter($matches[0], static fn (string $token): bool => ! str_starts_with($token, '--') && ! str_starts_with($token, '/*')));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{count: int, sha256: string}
     */
    private static function rowFingerprint(array $rows): array
    {
        $digests = [];
        foreach ($rows as $row) {
            ksort($row);
            // serialize preserves scalar kinds and binary values without UTF-8 coercion.
            $digests[] = hash('sha256', serialize($row));
        }
        sort($digests, SORT_STRING);

        return ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $digests))];
    }
}
