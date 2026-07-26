<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinus Sentinel Teach-mode enrollments — the user's personalised training set,
 * synced so a second machine inherits a trained detector.
 *
 * Privacy: these are derived YAMNet embeddings, never audio. Raw audio is
 * discarded on-device the moment the embedding is computed.
 *
 * Binary columns, not text. `embedding` holds the little-endian f32 bytes
 * exactly as the device's SQLite BLOB stores them, so there is no
 * float-formatting round trip anywhere in the path (and it is 4x smaller than a
 * JSON float array). `client_enrollment_uuid` holds the raw 16 uuid bytes. JSON
 * cannot carry raw bytes, so the API speaks base64 and decodes at the boundary.
 *
 * VARBINARY(16384) caps the embedding at 4096 dims; YAMNet's is 1024 (4096
 * bytes), leaving ample headroom well inside MySQL's 65535-byte row limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_sinus_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('phr_patient_id');
            $table->binary('client_enrollment_uuid', length: 16, fixed: true);
            $table->string('class', 32);
            $table->boolean('is_negative')->default(false);
            // How far a negative's veto reaches. Default false = every
            // class, which is right for a plain false-positive report; only
            // the negative half of a correction is scoped to `class`.
            $table->boolean('negative_scoped')->default(false);
            $table->binary('embedding', length: 16384);
            $table->unsignedSmallInteger('embedding_dim');
            $table->string('model_version', 64)->nullable();
            $table->float('similarity')->nullable();
            $table->float('separation')->nullable();
            $table->float('peak_dbfs')->nullable();
            // Links a negative back to the event whose misdetection produced it.
            // Matches phr_respiratory_events.client_event_uuid, which is a
            // string column on a shipped API contract.
            $table->string('source_event_uuid', 64)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->dateTime('captured_at');
            $table->timestamps();

            // Explicit short identifiers: MySQL caps them at 64 chars and
            // Laravel would generate names well over that for this table.
            $table->foreign('phr_patient_id', 'phr_sinus_enr_patient_fk')
                ->references('id')->on('phr_patients')->cascadeOnDelete();

            $table->unique(['phr_patient_id', 'client_enrollment_uuid'], 'phr_sinus_enr_patient_uuid_uq');
            $table->index(['phr_patient_id', 'class'], 'phr_sinus_enr_patient_class_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_sinus_enrollments');
    }
};
