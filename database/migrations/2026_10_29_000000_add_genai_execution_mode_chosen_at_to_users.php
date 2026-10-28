<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI extraction defaults to the AI Agent Inbox unless the user has saved a valid
 * provider key (issue #235). The mode stays a stored value; this timestamp says
 * whether the user chose it, so the default may move them (to server-side AI,
 * when they save a usable key) only while they have not. The old default was
 * `api`, so only `external` was ever a choice: those users are marked as having
 * chosen. Users left on `api` with no usable key could not have had a document
 * processed; they move to the inbox, unchosen.
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
        User::query()->where('genai_execution_mode', 'api')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                if (! $user->hasUsableAiProvider()) {
                    DB::table('users')->where('id', $user->id)->update(['genai_execution_mode' => 'external']);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('genai_execution_mode_chosen_at');
        });
    }
};
