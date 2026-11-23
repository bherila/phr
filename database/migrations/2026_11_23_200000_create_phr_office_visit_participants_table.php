<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visit's care team (issue #412): each person, their credentials, their role as an
 * HL7 v3 ParticipationType code (ATND, CON, ADM, DIS, PPRF, SPRF, else PART) with the
 * source's own label beside it, their specialty, whether they are primary, and the
 * period they were on the team. See PhrVisitParticipants.
 *
 * A participant has no review status of its own: it is part of its visit, reviewed and
 * exported with it. `provider_name` stays on the visit as the denormalised primary
 * attending. The visit's `participants_digest` is a digest of its participant list, so
 * a change to the team changes the visit's version and reopens its review the way a
 * change to any of its own columns does. It is null for a visit with no participants,
 * which is every existing visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phr_office_visit_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('office_visit_id');
            $table->string('external_id', 255)->nullable();
            $table->string('name', 255);
            $table->string('credentials', 100)->nullable();
            $table->string('role_code', 8)->default('PART');
            $table->string('role_label', 255)->nullable();
            $table->string('specialty', 255)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'office_visit_id'], 'phr_visit_participants_patient_visit_idx');
            $table->index('office_visit_id', 'phr_visit_participants_visit_idx');

            $table->foreign('patient_id', 'phr_visit_participants_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('office_visit_id', 'phr_visit_participants_visit_fk')->references('id')->on('phr_office_visits')->cascadeOnDelete();
        });

        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->char('participants_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->dropColumn('participants_digest');
        });
        Schema::dropIfExists('phr_office_visit_participants');
    }
};
