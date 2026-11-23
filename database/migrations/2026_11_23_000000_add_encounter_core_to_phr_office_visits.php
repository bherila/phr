<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coded encounter facts beside the source's own labels (issue #410): the HL7 v3
 * ActCode class (inpatient, ambulatory, emergency, ...), the FHIR Encounter.status
 * state, the unit or location, and the admit source and discharge disposition, each a
 * code with the source's text beside it. See PhrEncounterVocabulary.
 *
 * `encounter_status` keeps what the source called the appointment's status (EHI
 * APPT_STATUS_C_NAME); the coded state is `encounter_state`. Every column is nullable
 * and null on every existing row, and native backup reads them as null in an older
 * archive (PhrNativeLifecycleCompatibility).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->string('encounter_class', 16)->nullable();
            $table->string('encounter_state', 32)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('admit_source', 32)->nullable();
            $table->string('admit_source_text', 255)->nullable();
            $table->string('discharge_disposition', 32)->nullable();
            $table->string('discharge_disposition_text', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->dropColumn([
                'encounter_class',
                'encounter_state',
                'location',
                'admit_source',
                'admit_source_text',
                'discharge_disposition',
                'discharge_disposition_text',
            ]);
        });
    }
};
