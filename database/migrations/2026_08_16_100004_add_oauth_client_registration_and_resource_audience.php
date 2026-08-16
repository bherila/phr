<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->timestamp('dynamically_registered_at')->nullable()->index();
            $table->timestamp('first_authorized_at')->nullable();
            // Passport already treats a nullable `scopes` attribute as the
            // client's allow-list. Null preserves existing/static clients'
            // unrestricted behavior; dynamic registrations persist the exact
            // scope metadata they advertised so authorization cannot exceed it.
            $table->text('scopes')->nullable();
        });
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->string('resource_uri')->nullable();
        });
        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->string('resource_uri')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['resource_uri']);
            $table->dropColumn('resource_uri');
        });
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->dropColumn('resource_uri');
        });
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dropIndex(['dynamically_registered_at']);
            $table->dropColumn(['dynamically_registered_at', 'first_authorized_at', 'scopes']);
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
