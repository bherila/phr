<?php

namespace Tests\Feature\PHR;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DropForceLowercaseDisplayMigrationTest extends TestCase
{
    public function test_the_retired_setting_column_is_gone_and_the_migration_is_repeatable(): void
    {
        $migration = require database_path('migrations/2026_11_01_000004_drop_force_lowercase_display_from_users.php');
        $this->assertInstanceOf(Migration::class, $migration);

        $this->assertFalse(Schema::hasColumn('users', 'force_lowercase_display'));
        $migration->up();
        $this->assertFalse(Schema::hasColumn('users', 'force_lowercase_display'));

        $migration->down();
        $this->assertTrue(Schema::hasColumn('users', 'force_lowercase_display'));
        $migration->up();
        $this->assertFalse(Schema::hasColumn('users', 'force_lowercase_display'));
    }
}
