<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published from bherila/auth-laravel 0.23. The provider subject and credential
 * generation an authorization code, access token or refresh token was issued
 * under, and a refresh token's owner, indexed so revoking an account's
 * credentials finds its refresh tokens even after their access tokens are
 * purged. Nullable, and adds only absent columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
            $this->addColumn($table, 'provider_subject', function (Blueprint $blueprint): void {
                $blueprint->string('provider_subject', 191)->nullable();
            });
            $this->addColumn($table, 'provider_generation', function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('provider_generation')->nullable();
            });
        }
        $this->addColumn('oauth_refresh_tokens', 'provider_user_id', function (Blueprint $blueprint): void {
            $blueprint->string('provider_user_id', 191)->nullable()->index();
        });
    }

    public function down(): void {}

    /** @param callable(Blueprint): void $definition */
    private function addColumn(string $tableName, string $column, callable $definition): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, $definition);
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
