<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI extraction defaults to the AI Agent Inbox unless the user has saved a valid
 * provider key (issue #235). A user's stored mode counts only once they chose it
 * (this timestamp); until then the default decides. The old default was `api`,
 * so only an `external` mode was ever a choice: those users keep it. Everyone
 * else follows the new default: `api` while they have a usable key, otherwise the
 * inbox, which is where a user with no key could only have had work done.
 *
 * A new column rather than a nullable `genai_execution_mode`: changing a column
 * makes SQLite rebuild `users`, which drops the trigger that versions OAuth
 * security on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('genai_execution_mode_chosen_at')->nullable()->after('genai_execution_mode');
        });

        DB::table('users')->where('genai_execution_mode', 'external')->update(['genai_execution_mode_chosen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('genai_execution_mode_chosen_at');
        });
    }
};
