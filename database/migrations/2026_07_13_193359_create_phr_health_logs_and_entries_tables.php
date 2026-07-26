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
        Schema::create('phr_health_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('name', 120);
            $table->string('kind', 32);
            $table->string('description', 1000)->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'name'], 'phr_hl_patient_name_uid');
            $table->index(['patient_id', 'archived_at'], 'phr_hl_patient_arch_idx');
            $table->index('user_id', 'phr_hl_user_idx');
            $table->index('created_by_user_id', 'phr_hl_creator_idx');
            $table->foreign('patient_id', 'phr_hl_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_hl_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by_user_id', 'phr_hl_creator_fk')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('phr_health_log_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('health_log_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->dateTime('occurred_at');
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('intensity')->nullable();
            $table->json('tags')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['health_log_id', 'occurred_at'], 'phr_hle_log_at_idx');
            $table->index(['patient_id', 'occurred_at'], 'phr_hle_patient_at_idx');
            $table->index('user_id', 'phr_hle_user_idx');
            $table->index('recorded_by_user_id', 'phr_hle_recorder_idx');
            $table->foreign('health_log_id', 'phr_hle_log_fk')->references('id')->on('phr_health_logs')->cascadeOnDelete();
            $table->foreign('patient_id', 'phr_hle_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_hle_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('recorded_by_user_id', 'phr_hle_recorder_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phr_health_log_entries');
        Schema::dropIfExists('phr_health_logs');
    }
};
