<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_office_visit_dicom_studies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('office_visit_id');
            $table->unsignedBigInteger('dicom_study_id');
            $table->timestamps();

            $table->unique(['office_visit_id', 'dicom_study_id'], 'phr_visit_dicom_visit_study_uid');
            $table->index(['patient_id', 'office_visit_id'], 'phr_visit_dicom_patient_visit_idx');
            $table->index('dicom_study_id', 'phr_visit_dicom_study_idx');

            $table->foreign('patient_id', 'phr_visit_dicom_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('office_visit_id', 'phr_visit_dicom_visit_fk')->references('id')->on('phr_office_visits')->cascadeOnDelete();
            $table->foreign('dicom_study_id', 'phr_visit_dicom_study_fk')->references('id')->on('phr_dicom_studies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_office_visit_dicom_studies');
    }
};
