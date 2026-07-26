<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_negative_assertions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->string('import_source', 50)->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable();
            $table->string('assertion_type', 100);
            $table->text('statement');
            $table->string('scope')->nullable();
            $table->date('observed_on')->nullable();
            $table->text('notes')->nullable();
            $table->json('source_refs')->nullable();
            $table->longText('raw_text')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'assertion_type'], 'phr_na_patient_type_idx');
            $table->index('source_document_id', 'phr_na_src_doc_idx');
            $table->unique(['patient_id', 'import_source', 'external_id'], 'phr_na_imp_uid');

            $table->foreign('patient_id', 'phr_na_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_na_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('source_document_id', 'phr_na_src_doc_fk')->references('id')->on('phr_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_negative_assertions');
    }
};
