<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an EHI encounter needs beyond the existing visit columns (issue #213, M3):
 * which kind of event the source records, each date's verbatim text, precision, and
 * documented zone, and the diagnoses recorded on the encounter. Those stay on the
 * encounter: they are not longitudinal conditions, and no ICD-10 code is invented
 * for them. Every column is nullable and null on every existing row.
 *
 * Native backup reads all of them as null in an older archive: see
 * PhrNativeLifecycleCompatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->string('encounter_category', 16)->nullable();
            $table->string('encounter_status', 50)->nullable();
            $table->json('encounter_diagnoses')->nullable();
            $table->string('visit_date_source', 64)->nullable();
            $table->string('visit_started_source', 64)->nullable();
            $table->string('visit_started_precision', 16)->nullable();
            $table->string('visit_started_timezone', 16)->nullable();
            $table->string('visit_ended_source', 64)->nullable();
            $table->string('visit_ended_precision', 16)->nullable();
            $table->string('visit_ended_timezone', 16)->nullable();
        });

        Schema::table('phr_ehi_record_links', function (Blueprint $table): void {
            $table->foreignId('office_visit_id')->nullable()->constrained('phr_office_visits')->cascadeOnDelete();

            $table->unique(['revision_id', 'office_visit_id', 'link_kind'], 'phr_ehi_links_rev_visit_uq');
        });

        Schema::table('phr_ehi_proposal_decisions', function (Blueprint $table): void {
            $table->foreignId('linked_office_visit_id')->nullable()->constrained('phr_office_visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('phr_ehi_proposal_decisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('linked_office_visit_id');
        });
        Schema::table('phr_ehi_record_links', function (Blueprint $table): void {
            $table->dropUnique('phr_ehi_links_rev_visit_uq');
            $table->dropConstrainedForeignId('office_visit_id');
        });
        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->dropColumn([
                'encounter_category',
                'encounter_status',
                'encounter_diagnoses',
                'visit_date_source',
                'visit_started_source',
                'visit_started_precision',
                'visit_started_timezone',
                'visit_ended_source',
                'visit_ended_precision',
                'visit_ended_timezone',
            ]);
        });
    }
};
