<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_storage_settings', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('global_limit_bytes')->nullable();
            $table->unsignedBigInteger('default_user_limit_bytes')->nullable();
        });
        Schema::create('phr_user_storage_limits', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('limit_bytes')->nullable();
            $table->boolean('unlimited')->default(false);
        });
        Schema::table('phr_document_uploads', function (Blueprint $table): void {
            // Existing sessions continue on their original document disk.
            $table->string('storage_disk', 64)->default('phr_documents');
            $table->string('transport', 16)->default('local');
        });
    }

    public function down(): void
    {
        Schema::table('phr_document_uploads', function (Blueprint $table): void {
            $table->dropColumn(['storage_disk', 'transport']);
        });
        Schema::dropIfExists('phr_user_storage_limits');
        Schema::dropIfExists('phr_storage_settings');
    }
};
