<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('phr_patients', 'real_names')) {
            return;
        }

        Schema::table('phr_patients', function (Blueprint $table): void {
            $table->json('real_names')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('phr_patients', 'real_names')) {
            return;
        }

        Schema::table('phr_patients', function (Blueprint $table): void {
            $table->dropColumn('real_names');
        });
    }
};
