<?php

namespace Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\Database\SqliteBootstrapSnapshot;

final class SqliteBootstrapSnapshotTest extends TestCase
{
    public function test_missing_seed_rows_and_advanced_state_cannot_compare_equal(): void
    {
        $replay = $this->database();
        $dump = $this->database();
        $replay->exec("INSERT INTO synthetic_state VALUES ('clock', 0, '2026-11-13 00:00:00')");
        $this->assertNotSame($this->snapshot($replay)['rows'], $this->snapshot($dump)['rows']);
        $dump->exec("INSERT INTO synthetic_state VALUES ('clock', 0, '2026-11-13 00:00:00')");
        $this->assertSame($this->snapshot($replay)['rows'], $this->snapshot($dump)['rows']);
        $dump->exec('UPDATE synthetic_state SET sequence = 7');
        $this->assertNotSame($this->snapshot($replay)['rows'], $this->snapshot($dump)['rows']);
        $dump->exec("UPDATE synthetic_state SET sequence = 0, created_at = 'invalid-date'");
        $this->assertNotSame($this->snapshot($replay)['rows'], $this->snapshot($dump)['rows'], 'Timestamps are semantic data; invalid values must never be normalized away.');
    }

    public function test_row_order_is_irrelevant_but_duplicate_counts_and_scalar_kinds_are_not(): void
    {
        $one = $this->database();
        $two = $this->database();
        $one->exec("INSERT INTO synthetic_state VALUES ('a', 1, NULL), ('b', 2, NULL)");
        $two->exec("INSERT INTO synthetic_state VALUES ('b', 2, NULL), ('a', 1, NULL)");
        $this->assertSame($this->snapshot($one)['rows'], $this->snapshot($two)['rows']);
        $two->exec("INSERT INTO synthetic_state VALUES ('b', 2, NULL)");
        $this->assertNotSame($this->snapshot($one)['rows'], $this->snapshot($two)['rows']);
        $two->exec("DELETE FROM synthetic_state WHERE rowid = 3; UPDATE synthetic_state SET sequence = 'not-an-integer' WHERE name = 'a'");
        $this->assertNotSame($this->snapshot($one)['rows'], $this->snapshot($two)['rows']);
    }

    public function test_sql_layout_is_ignored_but_literal_whitespace_and_constraints_are_preserved(): void
    {
        $one = new PDO('sqlite::memory:');
        $two = new PDO('sqlite::memory:');
        $one->exec("CREATE TABLE synthetic_state (name TEXT DEFAULT 'a b', sequence INTEGER CHECK(sequence >= 0))");
        $two->exec("CREATE TABLE synthetic_state\n( name TEXT DEFAULT 'a b',\n sequence INTEGER CHECK ( sequence >= 0 ) )");
        $this->assertSame($this->snapshot($one)['structure'], $this->snapshot($two)['structure']);
        $two->exec("DROP TABLE synthetic_state; CREATE TABLE synthetic_state (name TEXT DEFAULT 'ab', sequence INTEGER CHECK(sequence >= 0))");
        $this->assertNotSame($this->snapshot($one)['structure'], $this->snapshot($two)['structure']);
        $two->exec("DROP TABLE synthetic_state; CREATE TABLE synthetic_state (name TEXT DEFAULT 'a b', sequence INTEGER CHECK(sequence > 0))");
        $this->assertNotSame($this->snapshot($one)['structure'], $this->snapshot($two)['structure']);
    }

    public function test_migration_names_are_compared_without_incidental_ids_or_batch_numbers(): void
    {
        $one = $this->database();
        $two = $this->database();
        $one->exec("INSERT INTO migrations VALUES (1, 'synthetic_create_state', 1)");
        $two->exec("INSERT INTO migrations VALUES (9, 'synthetic_create_state', 3)");
        $this->assertSame($this->snapshot($one), $this->snapshot($two));
        $two->exec("INSERT INTO migrations VALUES (10, 'synthetic_extra', 3)");
        $this->assertNotSame($this->snapshot($one)['migrations'], $this->snapshot($two)['migrations']);
    }

    public function test_row_diagnostics_are_hashes_without_raw_values(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO synthetic_state VALUES ('SYNTHETIC_PRIVATE_SENTINEL', 0, NULL)");
        $snapshot = $this->snapshot($db);
        $this->assertSame(1, $snapshot['rows']['synthetic_state']['count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['rows']['synthetic_state']['sha256']);
        $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_SENTINEL', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function database(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE migrations (id INTEGER, migration TEXT, batch INTEGER); CREATE TABLE synthetic_state (name TEXT, sequence INTEGER, created_at TEXT)');

        return $db;
    }

    /** @return array{migrations: list<string>, structure: array<string, array{table: string, tokens: list<string>}>, rows: array<string, array{count: int, sha256: string}>} */
    private function snapshot(PDO $db): array
    {
        return SqliteBootstrapSnapshot::capture($db);
    }
}
