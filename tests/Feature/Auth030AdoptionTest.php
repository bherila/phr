<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Auth030AdoptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_auth_table_is_upgraded_without_enabling_local_credential_routes(): void
    {
        $this->assertTrue(Schema::hasColumn('auth_two_factor_attempts', 'link_secret_hash'));
        $this->assertFalse(config('bherila-auth.routes.enabled'));
        $this->postJson('/api/auth/confirm-password', ['password' => 'synthetic-password'])->assertNotFound();
        $this->postJson('/api/auth/two-factor/confirm/synthetic-attempt')->assertNotFound();
    }

    public function test_link_hash_migration_preserves_legacy_rows_and_respects_the_configured_table(): void
    {
        $table = 'synthetic_legacy_auth_attempts';
        Schema::create($table, static function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('token');
            $blueprint->string('code');
        });
        DB::table($table)->insert(['token' => 'synthetic-legacy-token', 'code' => '123456']);
        config(['bherila-auth.two_factor.table' => $table]);
        $migration = require database_path('migrations/2026_11_15_000000_add_link_secret_hash_to_two_factor_attempts.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas($table, ['token' => 'synthetic-legacy-token', 'code' => '123456', 'link_secret_hash' => null]);
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn($table, 'link_secret_hash'));
        $this->assertDatabaseHas($table, ['token' => 'synthetic-legacy-token', 'code' => '123456']);
        Schema::dropIfExists($table);
    }
}
