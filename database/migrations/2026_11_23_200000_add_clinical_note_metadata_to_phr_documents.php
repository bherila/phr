<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical note metadata (phr#409): the source's LOINC code and label, who wrote
 * and who cosigned a note, when it was written and when signed, its FHIR
 * docStatus, and whether a consent or estimate awaits the patient's signature.
 * Every column is nullable: an existing document states none of it.
 *
 * Addenda live in their own table, `phr_document_relations`, not a foreign key on
 * `phr_documents`: native restore inserts a table's rows page by page, so a
 * reference from one document to another of the same table could point at a row
 * not yet restored. The link table is restored after every document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('phr_documents', 'loinc_code')) {
            Schema::table('phr_documents', function (Blueprint $table): void {
                $table->string('loinc_code', 20)->nullable();
                $table->string('source_type_label', 255)->nullable();
                $table->string('author_name', 255)->nullable();
                $table->string('author_role', 255)->nullable();
                $table->string('cosigner_name', 255)->nullable();
                $table->dateTime('authored_at')->nullable();
                $table->dateTime('signed_at')->nullable();
                $table->string('doc_status', 32)->nullable();
                $table->string('patient_signature_status', 32)->nullable();
                $table->dateTime('patient_signed_at')->nullable();
            });
        }

        if (! Schema::hasTable('phr_document_relations')) {
            Schema::create('phr_document_relations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('patient_id')->constrained('phr_patients')->cascadeOnDelete();
                // One relation per document: the document it appends to or replaces.
                $table->foreignId('document_id')->unique('phr_doc_rel_document_uid')->constrained('phr_documents')->cascadeOnDelete();
                // Null while the source's reference names no document PHR holds.
                $table->foreignId('related_document_id')->nullable()->constrained('phr_documents')->nullOnDelete();
                // The source's own reference (a FHIR reference, a C-CDA id), kept verbatim.
                $table->string('related_reference', 255)->nullable();
                $table->string('relation', 16);
                $table->timestamps();
                $table->index(['patient_id', 'related_document_id'], 'phr_doc_rel_related_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_document_relations');
        Schema::table('phr_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'loinc_code', 'source_type_label', 'author_name', 'author_role', 'cosigner_name',
                'authored_at', 'signed_at', 'doc_status', 'patient_signature_status', 'patient_signed_at',
            ]);
        });
    }
};
