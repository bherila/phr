<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to a home medication during a stay (#414): at admission or at discharge,
 * it was continued, held, changed or stopped, or an order was new. One row per transition,
 * a clinical record like the others (import source, external id, source document, review
 * status, soft delete and retraction), so it shares their review, versioning and lifecycle.
 *
 * A row names its visit, the home medication (none for a new order) and the inpatient or
 * discharge order (none for one held or stopped). Composite foreign keys hold each of them
 * to the row's own patient, and a check holds the action to the records it needs. What a
 * database cannot check across tables (the settings of the two medications) the model
 * checks before it saves (PhrMedicationTransitionIntegrity).
 *
 * Additive only: the previous release neither reads nor writes this table.
 */
return new class extends Migration
{
    private const string TABLE = 'phr_medication_transitions';

    /** Same rule as 2026_08_22_120000_make_agent_external_id_binary: external IDs compare bytewise. */
    private const string EXTERNAL_ID_COLLATION = 'utf8mb4_bin';

    private const string CHECK = 'phr_med_transitions_shape_chk';

    /**
     * The action holds the records it needs: held and stopped name a home medication and no
     * order, new names an order and no home medication, continued and changed name both.
     */
    private const string CHECK_EXPRESSION = "phase IN ('admission', 'discharge')"
        ." AND action IN ('continued', 'held', 'changed', 'stopped', 'new')"
        ." AND ((action IN ('held', 'stopped') AND home_medication_id IS NOT NULL AND order_medication_id IS NULL)"
        ." OR (action = 'new' AND home_medication_id IS NULL AND order_medication_id IS NOT NULL)"
        ." OR (action IN ('continued', 'changed') AND home_medication_id IS NOT NULL AND order_medication_id IS NOT NULL AND home_medication_id <> order_medication_id))";

    public function up(): void
    {
        // A record belongs to its patient: the composite keys below reference these.
        Schema::table('phr_medications', function (Blueprint $table): void {
            $table->unique(['id', 'patient_id'], 'phr_medications_id_patient_uid');
        });
        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->unique(['id', 'patient_id'], 'phr_office_visits_id_patient_uid');
        });

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('office_visit_id');
            $table->unsignedBigInteger('home_medication_id')->nullable();
            $table->unsignedBigInteger('order_medication_id')->nullable();
            $table->string('phase', 16);
            $table->string('action', 16);
            $table->text('note')->nullable();
            $table->string('import_source', 50)->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable()->index();
            $table->string('review_status', 32)->default('confirmed')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('retracted_at')->nullable();

            $table->foreign('patient_id', 'phr_med_transitions_patient_fk')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id', 'phr_med_transitions_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign(['office_visit_id', 'patient_id'], 'phr_med_transitions_visit_fk')->references(['id', 'patient_id'])->on('phr_office_visits')->cascadeOnDelete();
            $table->foreign(['home_medication_id', 'patient_id'], 'phr_med_transitions_home_fk')->references(['id', 'patient_id'])->on('phr_medications')->cascadeOnDelete();
            $table->foreign(['order_medication_id', 'patient_id'], 'phr_med_transitions_order_fk')->references(['id', 'patient_id'])->on('phr_medications')->cascadeOnDelete();

            $table->unique(['patient_id', 'import_source', 'external_id'], 'phr_med_transitions_imp_uid');
            $table->index(['patient_id', 'office_visit_id'], 'phr_med_transitions_visit_idx');
            $table->index(['home_medication_id', 'patient_id'], 'phr_med_transitions_home_idx');
            $table->index(['order_medication_id', 'patient_id'], 'phr_med_transitions_order_idx');
            $table->index(['patient_id', 'deleted_at', 'retracted_at'], 'phr_med_transitions_lifecycle_idx');
        });

        match (DB::getDriverName()) {
            // SQLite cannot add a constraint to a table it has created, so triggers refuse the row instead.
            'sqlite' => $this->sqliteChecks(),
            default => DB::statement(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', self::TABLE, self::CHECK, self::CHECK_EXPRESSION)),
        };

        // External IDs are opaque, case-sensitive identifiers; only the MySQL family needs the override.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `external_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE %s NULL', self::TABLE, self::EXTERNAL_ID_COLLATION));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
        Schema::table('phr_office_visits', function (Blueprint $table): void {
            $table->dropUnique('phr_office_visits_id_patient_uid');
        });
        Schema::table('phr_medications', function (Blueprint $table): void {
            $table->dropUnique('phr_medications_id_patient_uid');
        });
    }

    private function sqliteChecks(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            $expression = preg_replace('/\b(phase|action|home_medication_id|order_medication_id)\b/', 'NEW.$1', self::CHECK_EXPRESSION);
            DB::statement(sprintf(
                "CREATE TRIGGER %s_%s BEFORE %s ON %s FOR EACH ROW WHEN NOT (%s) BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: %s'); END",
                self::CHECK, $suffix, $event, self::TABLE, $expression, self::CHECK,
            ));
        }
    }
};
