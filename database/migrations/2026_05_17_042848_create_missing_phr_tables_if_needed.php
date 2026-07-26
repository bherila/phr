<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('phr_lab_results')) {
            Schema::create('phr_lab_results', function (Blueprint $table): void {
                $table->id();
                $table->string('user_id')->nullable();
                $table->string('test_name')->nullable();
                $table->timestamp('collection_datetime')->nullable();
                $table->timestamp('result_datetime')->nullable();
                $table->string('result_status', 50)->nullable();
                $table->string('ordering_provider', 100)->nullable();
                $table->string('resulting_lab', 100)->nullable();
                $table->string('analyte', 100)->nullable();
                $table->string('value', 20)->nullable();
                $table->string('unit', 20)->nullable();
                $table->decimal('range_min', 10, 2)->nullable();
                $table->decimal('range_max', 10, 2)->nullable();
                $table->string('range_unit', 20)->nullable();
                $table->string('normal_value', 50)->nullable();
                $table->mediumText('message_from_provider')->nullable();
                $table->mediumText('result_comment')->nullable();
                $table->string('lab_director', 100)->nullable();
            });
        }

        if (! Schema::hasTable('phr_patient_vitals')) {
            Schema::create('phr_patient_vitals', function (Blueprint $table): void {
                $table->id();
                $table->string('user_id', 50)->nullable();
                $table->string('vital_name')->nullable();
                $table->date('vital_date')->nullable();
                $table->string('vital_value')->nullable();
            });
        }
    }

    public function down(): void
    {
        //
    }
};
