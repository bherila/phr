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
        Schema::create('phr_office_visits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->date('visit_date')->nullable();
            $table->dateTime('visit_started_at')->nullable();
            $table->dateTime('visit_ended_at')->nullable();
            $table->string('visit_type', 100)->nullable();
            $table->string('provider_name', 255)->nullable();
            $table->string('provider_specialty', 100)->nullable();
            $table->string('facility_name', 255)->nullable();
            $table->text('chief_complaint')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->json('icd10_codes')->nullable();
            $table->json('cpt_codes')->nullable();
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
        Schema::dropIfExists('phr_office_visits');
    }
};
