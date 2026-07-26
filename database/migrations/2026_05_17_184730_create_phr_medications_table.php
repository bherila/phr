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
        Schema::create('phr_medications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 255);
            $table->string('rxnorm_code', 50)->nullable();
            $table->string('dose', 100)->nullable();
            $table->string('dose_unit', 50)->nullable();
            $table->string('route', 100)->nullable();
            $table->string('frequency', 100)->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('status', 50)->default('active');
            $table->string('prescriber_name', 255)->nullable();
            $table->text('reason_for_use')->nullable();
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
        Schema::dropIfExists('phr_medications');
    }
};
