<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Database\SqliteBootstrapSnapshot;

/** Full migration replay is intentionally CI-only, including when the suite uses PostgreSQL. */
final class SchemaDumpParityTest extends TestCase
{
    public function test_the_committed_dump_and_full_migration_replay_have_identical_structure_inventory_and_durable_rows(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 13)->startOfDay());
        $dump = database_path('schema/sqlite-schema.sql');
        $noDump = database_path('schema/.parity-no-dump.sql');
        $this->assertFileExists($dump);
        $this->assertFileDoesNotExist($noDump);
        $loaded = [];
        Event::listen(SchemaLoaded::class, function (SchemaLoaded $event) use (&$loaded): void {
            $loaded[] = ['connection' => $event->connection->getName(), 'path' => $event->path, 'migration_count' => $event->connection->table('migrations')->count()];
        });
        $migrator = app('migrator');
        $expected = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
        sort($expected);
        $this->assertNotEmpty($expected);

        try {
            $snapshots = [];
            foreach (['parity_dump' => $dump, 'parity_replay' => $noDump] as $name => $schemaPath) {
                // No inherited URL, host or database name. Neither the normal test
                // database nor the optional PostgreSQL suite connection is migrated.
                config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true, 'url' => null], 'passport.connection' => $name]);
                $connection = DB::connection($name);
                $this->assertSame('sqlite', $connection->getDriverName());
                $this->assertSame(':memory:', $connection->getDatabaseName());
                $this->assertSame([], $connection->getSchemaBuilder()->getTables());
                $this->assertSame(0, Artisan::call('migrate', ['--database' => $name, '--schema-path' => $schemaPath, '--force' => true, '--no-interaction' => true]));
                $snapshots[$name] = SqliteBootstrapSnapshot::capture($connection->getPdo());
            }
            $this->assertCount(1, $loaded, 'Exactly the dump path must load a schema; full replay must not.');
            $this->assertSame('parity_dump', $loaded[0]['connection']);
            $this->assertSame($dump, $loaded[0]['path']);
            $this->assertGreaterThan(0, $loaded[0]['migration_count'], 'The committed migration inventory must be present when SchemaLoaded fires.');
            foreach ($snapshots as $snapshot) {
                $this->assertSame($expected, $snapshot['migrations']);
            }
            foreach (['structure', 'rows'] as $section) {
                $this->assertSame(array_keys($snapshots['parity_replay'][$section]), array_keys($snapshots['parity_dump'][$section]), 'Bootstrap '.$section.' inventory must match.');
                foreach ($snapshots['parity_replay'][$section] as $object => $expectedState) {
                    $this->assertSame($expectedState, $snapshots['parity_dump'][$section][$object], 'Bootstrap parity mismatch: '.$section.' '.$object.'. Row values are hashed, never printed.');
                }
            }
        } finally {
            DB::purge('parity_dump');
            DB::purge('parity_replay');
        }
    }
}
