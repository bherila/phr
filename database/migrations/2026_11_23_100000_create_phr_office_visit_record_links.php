<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The records made during an encounter (#411), one link table per record type in the
 * style of phr_office_visit_eobs. A record belongs to at most one encounter, so each
 * table is also unique on its record column.
 *
 * Link tables rather than an encounter_id column: native restore inserts tables in
 * catalog order with relationships pointing backward, and phr_documents is restored
 * before the visits that name it as their source document.
 */
return new class extends Migration
{
    /** @var array<string, array{column: string, target: string, prefix: string}> */
    private const array TABLES = [
        'phr_office_visit_documents' => ['column' => 'document_id', 'target' => 'phr_documents', 'prefix' => 'phr_visit_docs'],
        'phr_office_visit_lab_results' => ['column' => 'lab_result_id', 'target' => 'phr_lab_results', 'prefix' => 'phr_visit_labs'],
        'phr_office_visit_vitals' => ['column' => 'vital_id', 'target' => 'phr_patient_vitals', 'prefix' => 'phr_visit_vitals'],
        'phr_office_visit_medications' => ['column' => 'medication_id', 'target' => 'phr_medications', 'prefix' => 'phr_visit_meds'],
        'phr_office_visit_procedures' => ['column' => 'procedure_id', 'target' => 'phr_procedures', 'prefix' => 'phr_visit_procs'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $definition) {
            $column = $definition['column'];
            $prefix = $definition['prefix'];
            Schema::create($name, function (Blueprint $table) use ($column, $prefix, $definition): void {
                $table->id();
                $table->unsignedBigInteger('patient_id');
                $table->unsignedBigInteger('office_visit_id');
                $table->unsignedBigInteger($column);
                $table->timestamps();

                $table->unique(['office_visit_id', $column], $prefix.'_visit_record_uid');
                // A record belongs to at most one encounter.
                $table->unique($column, $prefix.'_record_uid');
                $table->index(['patient_id', 'office_visit_id'], $prefix.'_patient_visit_idx');

                $table->foreign('patient_id', $prefix.'_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
                $table->foreign('office_visit_id', $prefix.'_visit_fk')->references('id')->on('phr_office_visits')->cascadeOnDelete();
                $table->foreign($column, $prefix.'_record_fk')->references('id')->on($definition['target'])->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::TABLES)) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
