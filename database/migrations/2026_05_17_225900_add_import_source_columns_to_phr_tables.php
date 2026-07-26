<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = [
        'phr_lab_results' => 'phr_labs_imp_uid',
        'phr_patient_vitals' => 'phr_vitals_imp_uid',
        'phr_office_visits' => 'phr_visits_imp_uid',
        'phr_medications' => 'phr_meds_imp_uid',
        'phr_conditions' => 'phr_conds_imp_uid',
        'phr_procedures' => 'phr_procs_imp_uid',
        'phr_immunizations' => 'phr_imms_imp_uid',
        'phr_allergies' => 'phr_allergies_imp_uid',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->string('import_source', 50)->nullable()->after('user_id');
                $table->string('external_id')->nullable()->after('import_source');
                $table->unique(['patient_id', 'import_source', 'external_id'], $indexName);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
                $table->dropColumn(['import_source', 'external_id']);
            });
        }
    }
};
