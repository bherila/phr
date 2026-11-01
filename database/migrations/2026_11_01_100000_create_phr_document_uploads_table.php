<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed document upload sessions (phr#268): an agent asks for a short-lived,
 * single-use URL, PUTs the bytes to it, and finalizes to create the document.
 *
 * Additive only. The bytes wait in staging under `staging_path` until finalize
 * moves them into a document, or the session expires and they are reclaimed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('phr_document_uploads')) {
            return;
        }

        Schema::create('phr_document_uploads', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('import_source', 50);
            $table->string('kind', 32);
            $table->string('filename', 255);
            $table->string('content_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->string('staging_path', 500)->nullable();
            $table->unsignedBigInteger('received_size')->nullable();
            $table->char('received_sha256', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('phr_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_document_uploads');
    }
};
