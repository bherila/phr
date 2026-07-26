<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('format', 20)->default('zip');
            $table->json('formats_json')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('storage_disk', 80)->default('phr_exports');
            $table->string('storage_path', 512)->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at'], 'phr_exports_patient_created_idx');
            $table->index(['status', 'expires_at'], 'phr_exports_status_exp_idx');

            $table->foreign('patient_id', 'phr_exports_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_exports_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('requested_by_user_id', 'phr_exports_req_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_exports');
    }
};
