<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SafeTestCase — runtime guard that throws if tests ever connect to a non-SQLite database.
 *
 * This class is an additional safety layer on top of the bootstrap.php and phpunit.xml
 * configuration. It verifies the active database connection at setUp time rather than
 * relying solely on config values, ensuring no test can accidentally hit MySQL.
 *
 * PHR_TEST_DATABASE=pgsql instead allows a phr_test_* database on a local PostgreSQL server.
 *
 * Includes RefreshDatabase trait to run migrations before each test.
 */
abstract class SafeTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUpTraits(): array
    {
        $traceDirectory = getenv('PHR_TEST_TRACE_DIR');
        if (is_string($traceDirectory) && $traceDirectory !== '') {
            file_put_contents(
                $traceDirectory.'/phr-test-worker-'.((int) getenv('TEST_TOKEN')).'.log',
                static::class.'::'.$this->name()."\n",
                FILE_APPEND,
            );
        }

        $this->assertDatabaseIsSafeSqlite();

        $traits = parent::setUpTraits();

        // PostgreSQL sequences survive the rollback between tests, and some tests rely
        // on ids starting at 1, as they do on a fresh SQLite database.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select("SELECT setval(c.oid::regclass, 1, false) FROM pg_class c WHERE c.relkind = 'S' AND c.relnamespace = 'public'::regnamespace");
        }

        return $traits;
    }

    /**
     * Rows that break a foreign key. SQLite can defer checks during a restore;
     * PostgreSQL refuses them when they happen, so it has nothing left to report.
     *
     * @return list<object>
     */
    protected function foreignKeyViolations(): array
    {
        return DB::connection()->getDriverName() === 'sqlite' ? DB::select('PRAGMA foreign_key_check') : [];
    }

    /**
     * Assert that the active database connection is SQLite in-memory.
     *
     * @throws RuntimeException if the driver is not sqlite or the database is not :memory:
     */
    protected function assertDatabaseIsSafeSqlite(): void
    {
        $connection = DB::connection();
        $driverName = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if (
            getenv('PHR_TEST_DATABASE') === 'pgsql'
            && $driverName === 'pgsql'
            && str_starts_with((string) $database, 'phr_test_')
            && in_array($connection->getConfig('host'), ['127.0.0.1', '::1', 'localhost'], true)
        ) {
            return;
        }

        if ($driverName !== 'sqlite') {
            throw new RuntimeException(
                "SAFETY ERROR: Tests must use SQLite, but '{$driverName}' connection is active. ".
                'Ensure phpunit.xml bootstrap points to tests/bootstrap.php.'
            );
        }

        if ($database !== ':memory:') {
            throw new RuntimeException(
                "SAFETY ERROR: Tests must use in-memory SQLite, but database is '{$database}'."
            );
        }
    }
}
