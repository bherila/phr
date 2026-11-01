<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-user "force lowercase display" accessibility preference. It is
 * applied when the interface renders and never changes stored data. Additive,
 * defaulting to off, so the previous release ignores it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'force_lowercase_display')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('force_lowercase_display')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'force_lowercase_display')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('force_lowercase_display');
        });
    }
};
