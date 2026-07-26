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
        Schema::create('phr_procedures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 255);
            $table->string('cpt_code', 20)->nullable();
            $table->string('snomed_code', 50)->nullable();
            $table->dateTime('performed_at')->nullable();
            $table->date('performed_on')->nullable();
            $table->string('performer_name', 255)->nullable();
            $table->string('performer_specialty', 100)->nullable();
            $table->string('facility_name', 255)->nullable();
            $table->string('status', 50)->default('completed');
            $table->text('reason')->nullable();
            $table->text('outcome')->nullable();
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
        Schema::dropIfExists('phr_procedures');
    }
};
