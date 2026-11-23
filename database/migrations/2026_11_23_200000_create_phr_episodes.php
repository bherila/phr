<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Episodes of care (#415): a story that spans several encounters, such as an earlier
 * pneumonia admission and the current stay it led to.
 *
 * An episode carries the same agent-record columns as the other clinical tables
 * (import source, external ID, source document, review status, soft delete and
 * retraction) so it shares their review, versioning and lifecycle machinery.
 * phr_episode_encounters groups visits under it. The link points backward in
 * native-restore order (episodes and visits are both restored before it), and an
 * encounter may belong to more than one episode, as FHIR Encounter.episodeOfCare allows.
 *
 * Additive only: the previous release neither reads nor writes these tables.
 */
return new class extends Migration
{
    /** The agent-writable table this migration creates; keyed on external_id like the others. */
    private const string TABLE = 'phr_episodes';

    /** Same rule as 2026_08_22_120000_make_agent_external_id_binary: external IDs compare bytewise. */
    private const string EXTERNAL_ID_COLLATION = 'utf8mb4_bin';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('import_source', 50)->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable()->index();
            $table->string('review_status', 32)->default('confirmed')->index();
            $table->string('kind', 32)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('title', 255);
            $table->text('reason')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('retracted_at')->nullable();

            $table->foreign('patient_id')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['patient_id', 'import_source', 'external_id'], 'phr_episodes_imp_uid');
            $table->index(['patient_id', 'started_at'], 'phr_episodes_patient_started_idx');
            $table->index(['patient_id', 'deleted_at', 'retracted_at'], 'phr_episodes_lifecycle_idx');
        });

        // External IDs are opaque, case-sensitive identifiers. SQLite and PostgreSQL
        // already compare bytewise; only the MySQL family needs the override.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `external_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE %s NULL', self::TABLE, self::EXTERNAL_ID_COLLATION));
        }

        Schema::create('phr_episode_encounters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('episode_id');
            $table->unsignedBigInteger('office_visit_id');
            $table->timestamps();

            $table->unique(['episode_id', 'office_visit_id'], 'phr_episode_enc_pair_uid');
            $table->index(['patient_id', 'office_visit_id'], 'phr_episode_enc_patient_visit_idx');

            $table->foreign('patient_id', 'phr_episode_enc_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('episode_id', 'phr_episode_enc_episode_fk')->references('id')->on(self::TABLE)->cascadeOnDelete();
            $table->foreign('office_visit_id', 'phr_episode_enc_visit_fk')->references('id')->on('phr_office_visits')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_episode_encounters');
        Schema::dropIfExists(self::TABLE);
    }
};
