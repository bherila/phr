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
 * Includes RefreshDatabase trait to run migrations before each test.
 */
abstract class SafeTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUpTraits(): array
    {
        $this->assertDatabaseIsSafeSqlite();

        return parent::setUpTraits();
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
