<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that vouches for one fill beyond the document it was first read
 * from (phr#268): the label, the receipt and the EOB line of one dispensing are
 * one fill with several pieces of evidence. A row points at a document, at the
 * EOB line that adjudicated the fill, or both.
 *
 * Also adds `review_flags` to fills, where a write that could not be sure it
 * was not a duplicate says so for the reviewer.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_medication_fill_evidence')) {
            Schema::create('phr_medication_fill_evidence', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('patient_id')->index();
                $table->unsignedBigInteger('fill_id');
                $table->unsignedBigInteger('document_id')->nullable()->index();
                $table->unsignedBigInteger('eob_line_id')->nullable()->index();
                $table->string('source_kind', 32);
                $table->string('source_locator', 255)->nullable();
                $table->char('evidence_key', 40);
                $table->timestamps();

                $table->unique(['fill_id', 'evidence_key'], 'phr_fill_evidence_uid');
                $table->foreign('patient_id')->references('id')->on('phr_patients')->cascadeOnDelete();
                $table->foreign('fill_id')->references('id')->on('phr_medication_fills')->cascadeOnDelete();
                $table->foreign('document_id')->references('id')->on('phr_documents')->nullOnDelete();
                $table->foreign('eob_line_id')->references('id')->on('phr_eob_lines')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('phr_medication_fills', 'review_flags')) {
            Schema::table('phr_medication_fills', function (Blueprint $table): void {
                $table->json('review_flags')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_medication_fill_evidence');
        if (Schema::hasColumn('phr_medication_fills', 'review_flags')) {
            Schema::table('phr_medication_fills', function (Blueprint $table): void {
                $table->dropColumn('review_flags');
            });
        }
    }
};
