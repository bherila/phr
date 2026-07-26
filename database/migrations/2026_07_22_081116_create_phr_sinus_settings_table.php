<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinus Sentinel detection settings, one row per patient, synced last-write-wins
 * across the user's devices.
 *
 * `settings` is an opaque JSON document rather than typed columns (same shape as
 * `plan_tool_states.inputs`) so adding a per-class threshold later needs no
 * migration. Only detection-shaping keys live here — sensitivity and quiet
 * hours. Device-local settings (server URL, patient id, device id, model path,
 * sync mode) deliberately never sync.
 *
 * `settings_updated_at` is the client's clock and is the last-write-wins
 * comparand; `received_at` is the server's own clock, kept for tiebreaking and
 * forensics when a device's clock is wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_sinus_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('phr_patient_id');
            $table->json('settings');
            $table->dateTime('settings_updated_at');
            $table->dateTime('received_at');
            $table->string('updated_by_device', 64)->nullable();
            $table->timestamps();

            // Explicit short identifiers: MySQL caps them at 64 chars and
            // Laravel's generated names are long for this table prefix.
            $table->foreign('phr_patient_id', 'phr_sinus_set_patient_fk')
                ->references('id')->on('phr_patients')->cascadeOnDelete();

            $table->unique('phr_patient_id', 'phr_sinus_set_patient_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_sinus_settings');
    }
};
