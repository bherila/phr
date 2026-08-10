<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_office_visit_eobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('office_visit_id');
            $table->unsignedBigInteger('eob_id');
            $table->timestamps();

            $table->unique(['office_visit_id', 'eob_id'], 'phr_visit_eobs_visit_eob_uid');
            $table->index(['patient_id', 'office_visit_id'], 'phr_visit_eobs_patient_visit_idx');
            $table->index('eob_id', 'phr_visit_eobs_eob_idx');

            $table->foreign('patient_id', 'phr_visit_eobs_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('office_visit_id', 'phr_visit_eobs_visit_fk')->references('id')->on('phr_office_visits')->cascadeOnDelete();
            $table->foreign('eob_id', 'phr_visit_eobs_eob_fk')->references('id')->on('phr_eobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_office_visit_eobs');
    }
};
