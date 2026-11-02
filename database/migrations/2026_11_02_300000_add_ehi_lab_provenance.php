<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an EHI lab result needs beyond the existing columns (issue #213, M3):
 * the source's raw value and comparator, its own component code, and for each
 * date the verbatim text, its precision, and what zone the source documents.
 * Every column is nullable and null on every existing row; nothing is backfilled.
 *
 * Evidence links and decision history gain a typed target per the M2 design, and
 * a link records which versioned source-ID bridge justified it. Native backup
 * reads all of them as null in an older archive: see
 * PhrNativeLifecycleCompatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_lab_results', function (Blueprint $table): void {
            // As the source wrote it (a locale comma decimal stays); `value` is the stored result.
            $table->string('value_raw')->nullable();
            // The inequality the source states, kept beside the untouched `value`.
            $table->string('value_comparator', 8)->nullable();
            $table->string('analyte_code', 64)->nullable();
            $table->string('analyte_code_system', 32)->nullable();
            $table->string('collected_at_source', 64)->nullable();
            $table->string('collected_at_precision', 16)->nullable();
            $table->string('collected_at_timezone', 16)->nullable();
            $table->string('resulted_at_source', 64)->nullable();
            $table->string('resulted_at_precision', 16)->nullable();
            $table->string('resulted_at_timezone', 16)->nullable();
        });

        Schema::table('phr_ehi_record_links', function (Blueprint $table): void {
            $table->foreignId('lab_result_id')->nullable()->constrained('phr_lab_results')->cascadeOnDelete();
            // Set on a bridged link: the bridge contract version and the rule that held.
            $table->string('bridge_version', 32)->nullable();
            $table->string('bridge_rule', 64)->nullable();

            $table->unique(['revision_id', 'lab_result_id', 'link_kind'], 'phr_ehi_links_rev_lab_uq');
        });

        Schema::table('phr_ehi_proposal_decisions', function (Blueprint $table): void {
            $table->foreignId('linked_lab_result_id')->nullable()->constrained('phr_lab_results')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('phr_ehi_proposal_decisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('linked_lab_result_id');
        });
        Schema::table('phr_ehi_record_links', function (Blueprint $table): void {
            $table->dropUnique('phr_ehi_links_rev_lab_uq');
            $table->dropConstrainedForeignId('lab_result_id');
            $table->dropColumn(['bridge_version', 'bridge_rule']);
        });
        Schema::table('phr_lab_results', function (Blueprint $table): void {
            $table->dropColumn([
                'value_raw',
                'value_comparator',
                'analyte_code',
                'analyte_code_system',
                'collected_at_source',
                'collected_at_precision',
                'collected_at_timezone',
                'resulted_at_source',
                'resulted_at_precision',
                'resulted_at_timezone',
            ]);
        });
    }
};
