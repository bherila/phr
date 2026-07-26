<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('phr_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 255);
            $table->string('icd10_code', 20)->nullable();
            $table->string('snomed_code', 50)->nullable();
            $table->date('onset_date')->nullable();
            $table->date('abated_date')->nullable();
            $table->string('clinical_status', 50)->default('active');
            $table->string('verification_status', 50)->default('confirmed');
            $table->string('severity', 50)->nullable();
            $table->text('notes')->nullable();
            $table->longText('raw_text')->nullable();
            $table->timestamps();

            $table->foreign('patient_id')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phr_conditions');
    }
};
