<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->string('transport', 16)->default('chunks');
            // Set only while a multipart upload is open at the store.
            $table->string('multipart_upload_id', 1024)->nullable();
            $table->unsignedBigInteger('part_size_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phr_ehi_uploads', function (Blueprint $table): void {
            $table->dropColumn(['transport', 'multipart_upload_id', 'part_size_bytes']);
        });
    }
};
