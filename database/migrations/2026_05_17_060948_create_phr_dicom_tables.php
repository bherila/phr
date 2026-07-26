<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_dicom_uploads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('uploaded_by_user_id');
            $table->string('status', 32)->default('pending');
            $table->string('original_root_name')->nullable();
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('stored_files')->default(0);
            $table->unsignedInteger('skipped_files')->default(0);
            $table->unsignedBigInteger('total_bytes')->default(0);
            $table->unsignedBigInteger('stored_bytes')->default(0);
            $table->string('r2_prefix', 512);
            $table->json('manifest_json')->nullable();
            $table->json('skipped_files_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('patient_id', 'phr_dicom_uploads_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'phr_dicom_uploads_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['patient_id', 'created_at'], 'phr_dicom_uploads_patient_created_idx');
            $table->index('uploaded_by_user_id', 'phr_dicom_uploads_user_idx');
        });

        Schema::create('phr_dicom_studies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('upload_id')->nullable();
            $table->string('study_instance_uid', 128);
            $table->date('study_date')->nullable();
            $table->string('study_time', 32)->nullable();
            $table->string('accession_number', 128)->nullable();
            $table->string('description')->nullable();
            $table->string('modalities')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('patient_id', 'phr_dicom_studies_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('upload_id', 'phr_dicom_studies_upload_fk')->references('id')->on('phr_dicom_uploads')->nullOnDelete();
            $table->unique(['patient_id', 'study_instance_uid'], 'phr_dicom_studies_patient_uid_unique');
            $table->index(['patient_id', 'study_date'], 'phr_dicom_studies_patient_date_idx');
        });

        Schema::create('phr_dicom_series', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('study_id');
            $table->string('series_instance_uid', 128);
            $table->string('modality', 16)->nullable();
            $table->integer('series_number')->nullable();
            $table->string('description')->nullable();
            $table->string('body_part', 100)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('patient_id', 'phr_dicom_series_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('study_id', 'phr_dicom_series_study_fk')->references('id')->on('phr_dicom_studies')->cascadeOnDelete();
            $table->unique(['study_id', 'series_instance_uid'], 'phr_dicom_series_study_uid_unique');
            $table->index(['patient_id', 'modality'], 'phr_dicom_series_patient_modality_idx');
        });

        Schema::create('phr_dicom_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('upload_id');
            $table->string('file_kind', 32);
            $table->string('r2_key', 1024);
            $table->string('original_relative_path', 1024);
            $table->char('original_path_hash', 64);
            $table->string('original_filename');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->char('sha256', 64);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('patient_id', 'phr_dicom_files_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('upload_id', 'phr_dicom_files_upload_fk')->references('id')->on('phr_dicom_uploads')->cascadeOnDelete();
            $table->unique(['upload_id', 'original_path_hash'], 'phr_dicom_files_upload_path_unique');
            $table->index(['patient_id', 'sha256'], 'phr_dicom_files_patient_sha_idx');
        });

        Schema::create('phr_dicom_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('study_id');
            $table->unsignedBigInteger('series_id');
            $table->unsignedBigInteger('upload_id');
            $table->unsignedBigInteger('file_id');
            $table->string('sop_instance_uid', 128);
            $table->string('sop_class_uid', 128)->nullable();
            $table->integer('instance_number')->nullable();
            $table->string('transfer_syntax_uid', 128)->nullable();
            $table->integer('rows')->nullable();
            $table->integer('columns')->nullable();
            $table->integer('number_of_frames')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('patient_id', 'phr_dicom_instances_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('study_id', 'phr_dicom_instances_study_fk')->references('id')->on('phr_dicom_studies')->cascadeOnDelete();
            $table->foreign('series_id', 'phr_dicom_instances_series_fk')->references('id')->on('phr_dicom_series')->cascadeOnDelete();
            $table->foreign('upload_id', 'phr_dicom_instances_upload_fk')->references('id')->on('phr_dicom_uploads')->cascadeOnDelete();
            $table->foreign('file_id', 'phr_dicom_instances_file_fk')->references('id')->on('phr_dicom_files')->cascadeOnDelete();
            $table->unique(['patient_id', 'sop_instance_uid'], 'phr_dicom_instances_patient_sop_unique');
            $table->index(['series_id', 'instance_number'], 'phr_dicom_instances_series_num_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_dicom_instances');
        Schema::dropIfExists('phr_dicom_files');
        Schema::dropIfExists('phr_dicom_series');
        Schema::dropIfExists('phr_dicom_studies');
        Schema::dropIfExists('phr_dicom_uploads');
    }
};
