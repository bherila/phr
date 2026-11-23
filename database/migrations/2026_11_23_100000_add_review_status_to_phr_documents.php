<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document an agent, an AI read or a standards import wrote waits for a person's
 * review like every other machine-written record (phr#408), and only a confirmed one
 * leaves PHR in a FHIR or C-CDA export. Every existing row is `confirmed`, as are a
 * person's own browser uploads and what the console commands store: nothing about
 * them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('phr_documents', 'review_status')) {
            return;
        }

        Schema::table('phr_documents', function (Blueprint $table): void {
            $table->string('review_status', 32)->default('confirmed');
            $table->index(['patient_id', 'review_status'], 'phr_documents_patient_review_idx');
        });
    }

    public function down(): void
    {
        Schema::table('phr_documents', function (Blueprint $table): void {
            $table->dropIndex('phr_documents_patient_review_idx');
            $table->dropColumn('review_status');
        });
    }
};
