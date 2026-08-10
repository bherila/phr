<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_procedure_eobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('procedure_id');
            $table->unsignedBigInteger('eob_id');
            $table->timestamps();

            $table->unique(['procedure_id', 'eob_id'], 'phr_procedure_eobs_procedure_eob_uid');
            $table->index(['patient_id', 'procedure_id'], 'phr_procedure_eobs_patient_procedure_idx');
            $table->index('eob_id', 'phr_procedure_eobs_eob_idx');

            $table->foreign('patient_id', 'phr_procedure_eobs_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('procedure_id', 'phr_procedure_eobs_procedure_fk')->references('id')->on('phr_procedures')->cascadeOnDelete();
            $table->foreign('eob_id', 'phr_procedure_eobs_eob_fk')->references('id')->on('phr_eobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_procedure_eobs');
    }
};
