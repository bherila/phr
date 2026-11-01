<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'display_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('display_preferences')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'display_preferences')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('display_preferences');
        });
    }
};
