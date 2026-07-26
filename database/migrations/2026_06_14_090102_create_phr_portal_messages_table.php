<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_portal_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->string('import_source', 50)->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->dateTime('message_at')->nullable();
            $table->string('direction', 32)->nullable();
            $table->string('subject')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('recipient_name')->nullable();
            $table->text('summary')->nullable();
            $table->text('clinical_relevance')->nullable();
            $table->json('source_refs')->nullable();
            $table->longText('raw_text')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'message_at'], 'phr_pm_patient_at_idx');
            $table->index('source_document_id', 'phr_pm_src_doc_idx');
            $table->unique(['patient_id', 'import_source', 'external_id'], 'phr_pm_imp_uid');

            $table->foreign('patient_id', 'phr_pm_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_pm_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('source_document_id', 'phr_pm_src_doc_fk')->references('id')->on('phr_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_portal_messages');
    }
};
