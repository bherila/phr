<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TRIGGER = 'users_increment_oauth_security_version';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('oauth_security_version')->default(0);
        });

        // This database-owned generation closes the gap left by Eloquent model
        // events: bulk/query-builder role changes bypass those events. Every role
        // transition permanently invalidates credentials stamped with an earlier
        // generation, even if the account is later re-enabled.
        match (DB::getDriverName()) {
            'sqlite' => DB::unprepared(sprintf(
                'CREATE TRIGGER %s AFTER UPDATE OF user_role ON users '
                .'FOR EACH ROW WHEN OLD.user_role IS NOT NEW.user_role BEGIN '
                .'UPDATE users SET oauth_security_version = OLD.oauth_security_version + 1 WHERE id = NEW.id; END',
                self::TRIGGER,
            )),
            'mysql', 'mariadb' => DB::unprepared(sprintf(
                'CREATE TRIGGER %s BEFORE UPDATE ON users FOR EACH ROW '
                .'SET NEW.oauth_security_version = CASE '
                .'WHEN NOT (OLD.user_role <=> NEW.user_role) THEN OLD.oauth_security_version + 1 '
                .'ELSE NEW.oauth_security_version END',
                self::TRIGGER,
            )),
            default => throw new RuntimeException('OAuth credential generations require a supported database trigger driver.'),
        };
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('oauth_security_version');
        });
    }
};
