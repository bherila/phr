<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the per-user "force lowercase display" setting: fills now get a default display
 * casing derived on the server. Guarded so it is safe to run on a database that never had it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'force_lowercase_display')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('force_lowercase_display');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'force_lowercase_display')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('force_lowercase_display')->default(false);
        });
    }
};
