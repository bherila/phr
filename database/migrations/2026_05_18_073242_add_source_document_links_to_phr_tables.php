<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = [
        'phr_lab_results' => 'phr_labs_src_doc_idx',
        'phr_patient_vitals' => 'phr_vitals_src_doc_idx',
        'phr_office_visits' => 'phr_visits_src_doc_idx',
        'phr_medications' => 'phr_meds_src_doc_idx',
        'phr_conditions' => 'phr_conds_src_doc_idx',
        'phr_procedures' => 'phr_procs_src_doc_idx',
        'phr_immunizations' => 'phr_imms_src_doc_idx',
        'phr_allergies' => 'phr_allergies_src_doc_idx',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexName): void {
                if (! Schema::hasColumn($tableName, 'source_document_id')) {
                    $table->unsignedBigInteger('source_document_id')->nullable()->after('external_id');
                }

                if (! Schema::hasIndex($tableName, $indexName)) {
                    $table->index('source_document_id', $indexName);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'source_document_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
                $table->dropColumn('source_document_id');
            });
        }
    }
};
