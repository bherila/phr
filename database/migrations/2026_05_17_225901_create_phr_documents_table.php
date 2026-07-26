<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->unsignedBigInteger('genai_job_id')->nullable();
            $table->string('title')->nullable();
            $table->string('document_type', 80)->default('general');
            $table->string('original_filename')->nullable();
            $table->string('storage_disk', 80)->default('phr_documents');
            $table->string('storage_path', 512)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->longText('extracted_text')->nullable();
            $table->text('summary')->nullable();
            $table->string('source', 80)->nullable();
            $table->string('import_source', 50)->nullable();
            $table->string('external_id')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index('patient_id', 'phr_docs_patient_idx');
            $table->index('user_id', 'phr_docs_user_idx');
            $table->index('genai_job_id', 'phr_docs_genai_idx');
            $table->unique(['patient_id', 'import_source', 'external_id'], 'phr_docs_imp_uid');

            $table->foreign('patient_id', 'phr_docs_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_docs_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'phr_docs_uploaded_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('genai_job_id', 'phr_docs_genai_job_fk')->references('id')->on('genai_import_jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_documents');
    }
};
