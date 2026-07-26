<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_respiratory_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('phr_patient_id');
            $table->string('client_event_uuid', 64);
            $table->string('event_type', 32);
            $table->dateTime('occurred_at');
            $table->integer('tz_offset_min')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->float('confidence')->nullable();
            $table->unsignedInteger('burst_count')->default(1);
            $table->string('source', 32)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('model_version', 64)->nullable();
            $table->timestamps();

            $table->foreign('phr_patient_id', 'phr_resp_evt_patient_fk')
                ->references('id')->on('phr_patients')->cascadeOnDelete();

            $table->unique(['phr_patient_id', 'client_event_uuid'], 'phr_resp_evt_patient_uuid_uq');
            $table->index(['phr_patient_id', 'occurred_at'], 'phr_resp_evt_patient_time_ix');
            $table->index(['phr_patient_id', 'event_type'], 'phr_resp_evt_patient_type_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_respiratory_events');
    }
};
