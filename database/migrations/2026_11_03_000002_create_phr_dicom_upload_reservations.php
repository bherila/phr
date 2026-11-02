<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bytes a signed URL allows at one staging key, counted against storage limits until
        // they are confirmed or reclaimed.
        Schema::create('phr_dicom_upload_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('upload_id');
            $table->string('staging_key', 255)->unique();
            $table->unsignedBigInteger('byte_size');
            $table->timestamp('expires_at');
            // The session won't record this file; only its URL expiring and its bytes going are left.
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('patient_id', 'phr_dicom_reservations_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('upload_id', 'phr_dicom_reservations_upload_fk')->references('id')->on('phr_dicom_uploads')->cascadeOnDelete();
            $table->index('patient_id', 'phr_dicom_reservations_patient_idx');
            $table->index(['upload_id', 'expires_at'], 'phr_dicom_reservations_upload_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_dicom_upload_reservations');
    }
};
