<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Until when a signed write URL for this upload can still land bytes. Patient deletion waits for it.
        Schema::table('phr_dicom_uploads', function (Blueprint $table): void {
            $table->timestamp('signed_url_expires_at')->nullable();
        });
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->timestamp('part_url_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phr_dicom_uploads', function (Blueprint $table): void {
            $table->dropColumn('signed_url_expires_at');
        });
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->dropColumn('part_url_expires_at');
        });
    }
};
