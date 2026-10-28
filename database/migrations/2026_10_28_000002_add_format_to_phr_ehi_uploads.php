<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A browser-uploaded ZIP is an Epic EHI Tables export or a MyChart record ZIP
 * (FHIR and C-CDA files). Intake decides which; null until it has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->string('format', 16)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->dropColumn('format');
        });
    }
};
