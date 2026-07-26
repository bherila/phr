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
        Schema::create('phr_immunizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('vaccine_name', 255);
            $table->string('cvx_code', 20)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('lot_number', 100)->nullable();
            $table->date('administered_on')->nullable();
            $table->unsignedSmallInteger('dose_number')->nullable();
            $table->unsignedSmallInteger('series_doses')->nullable();
            $table->string('site', 100)->nullable();
            $table->string('route', 100)->nullable();
            $table->string('administered_by', 255)->nullable();
            $table->string('facility_name', 255)->nullable();
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
        Schema::dropIfExists('phr_immunizations');
    }
};
