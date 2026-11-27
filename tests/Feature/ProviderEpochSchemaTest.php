<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use BWH\Auth\OAuth\Session\RememberedProviderLogins;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProviderEpochSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_remembered_logins_keep_legacy_baselines_nullable_and_can_capture_an_epoch(): void
    {
        $this->assertTrue(Schema::hasTable('provider_remembered_logins'));
        $this->assertTrue(Schema::hasColumn('provider_remembered_logins', 'epoch'));
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'provider_epoch'), $table.' records the recovery epoch');
        }
        DB::table('provider_remembered_logins')->insert([
            'guard' => 'web', 'user_id' => 'synthetic-account', 'provider' => 'bherila',
            'subject' => 'synthetic-subject', 'generation' => 7,
            'token_hash' => hash('sha256', 'synthetic-remember-token'), 'recorded_at' => now(),
        ]);
        $legacy = DB::table('provider_remembered_logins')->where('user_id', 'synthetic-account')->first();
        $this->assertNotNull($legacy);
        $this->assertNull($legacy->epoch);

        DB::table('provider_remembered_logins')->where('user_id', 'synthetic-account')->update(['epoch' => 2]);
        $captured = DB::table('provider_remembered_logins')->where('user_id', 'synthetic-account')->first();
        $this->assertNotNull($captured);
        $this->assertSame(2, (int) $captured->epoch);
        $this->assertSame(7, (int) $captured->generation);
        $this->assertSame($legacy->token_hash, $captured->token_hash);
    }

    public function test_oauth_epochs_use_the_passport_connection_while_remembered_epochs_stay_on_the_app_connection(): void
    {
        config([
            'database.connections.epoch_passport' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'passport.connection' => 'epoch_passport',
        ]);
        try {
            $passport = Schema::connection('epoch_passport');
            foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
                $passport->create($table, fn (Blueprint $blueprint) => $blueprint->string('id')->primary());
            }
            Schema::table('provider_remembered_logins', fn (Blueprint $table) => $table->dropColumn('epoch'));
            $migration = require database_path('migrations/2026_11_24_000000_add_provider_epoch_columns.php');
            $migration->up();
            $migration->up();

            foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
                $this->assertTrue($passport->hasColumn($table, 'provider_epoch'), $table.' uses the configured Passport connection');
                DB::connection('epoch_passport')->table($table)->insert(['id' => 'synthetic-legacy-token']);
                $this->assertNull(DB::connection('epoch_passport')->table($table)->where('id', 'synthetic-legacy-token')->value('provider_epoch'));
            }
            $this->assertTrue(Schema::hasColumn('provider_remembered_logins', 'epoch'));
            $this->assertFalse($passport->hasTable('provider_remembered_logins'));
        } finally {
            config(['passport.connection' => null]);
            DB::purge('epoch_passport');
        }
    }

    public function test_deleting_an_account_removes_only_its_remembered_provider_baselines(): void
    {
        $removed = User::factory()->create();
        $retained = User::factory()->create();
        foreach ([$removed, $retained] as $user) {
            DB::table(RememberedProviderLogins::TABLE)->insert([
                'guard' => 'web', 'user_id' => (string) $user->id, 'provider' => 'bherila',
                'subject' => 'synthetic-subject-'.$user->id, 'generation' => 7, 'epoch' => 2,
                'token_hash' => hash('sha256', 'synthetic-token-'.$user->id), 'recorded_at' => now(),
            ]);
        }
        $removed->delete();

        $this->assertDatabaseMissing(RememberedProviderLogins::TABLE, ['user_id' => (string) $removed->id]);
        $this->assertDatabaseHas(RememberedProviderLogins::TABLE, ['user_id' => (string) $retained->id, 'epoch' => 2]);
    }
}
