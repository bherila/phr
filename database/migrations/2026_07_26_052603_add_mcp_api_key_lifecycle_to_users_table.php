<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the MCP bearer token a lifecycle.
 *
 * `mcp_api_key` was a permanent, full-account credential: no expiry, no
 * last-used signal, and no way to issue or revoke one short of a manual
 * UPDATE in tinker. A token that cannot expire and is never observed is a
 * token nobody notices has been stolen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('mcp_api_key_expires_at')->nullable()->after('mcp_api_key')
                ->comment('Expiry for mcp_api_key. A key with no expiry is rejected.');
            $table->timestamp('mcp_api_key_last_used_at')->nullable()->after('mcp_api_key_expires_at')
                ->comment('Last time mcp_api_key authenticated a request, for spotting misuse.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mcp_api_key_expires_at', 'mcp_api_key_last_used_at']);
        });
    }
};
