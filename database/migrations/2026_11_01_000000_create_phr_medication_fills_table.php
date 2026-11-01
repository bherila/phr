<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription fills: one row per dispensing, with the drug resolved from its NDC.
 *
 * Additive only. The previous release neither reads nor writes this table, so
 * the deploy can run the migration before the new code is serving.
 *
 * A fill carries the same agent-record columns as the other clinical tables
 * (import source, external ID, source document, review status, soft delete and
 * retraction) so it shares their review, versioning and lifecycle machinery.
 */
return new class extends Migration
{
    /** The agent-writable table this migration creates; keyed on external_id like the others. */
    private const string TABLE = 'phr_medication_fills';

    /** Same rule as 2026_08_22_120000_make_agent_external_id_binary: external IDs compare bytewise. */
    private const string EXTERNAL_ID_COLLATION = 'utf8mb4_bin';

    public function up(): void
    {
        if (Schema::hasTable('phr_medication_fills')) {
            return;
        }

        Schema::create('phr_medication_fills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            // Linked or auto-created after the fill is saved; nullable so a fill
            // can be written before its medication exists and so a hard-deleted
            // medication never takes its fills with it.
            $table->unsignedBigInteger('medication_id')->nullable()->index();
            $table->string('import_source', 50)->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('source_document_id')->nullable()->index();
            $table->string('review_status', 32)->default('confirmed')->index();
            $table->string('patient_name_as_printed', 255)->nullable();

            // The drug, resolved by the server from the NDC.
            $table->string('ndc_as_captured', 64)->nullable();
            $table->string('ndc_10', 16)->nullable();
            $table->string('ndc_11', 16)->nullable();
            $table->string('ndc_status', 16)->default('manual');
            $table->json('substance_names')->nullable();
            $table->json('strengths')->nullable();
            $table->string('brand_name', 255)->nullable();
            $table->string('dosage_form', 255)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('labeler_name', 255)->nullable();
            $table->string('package_description', 500)->nullable();
            $table->string('rxcui', 50)->nullable();
            $table->string('drug_as_printed', 255)->nullable();
            $table->json('fda_snapshot')->nullable();
            $table->timestamp('fda_fetched_at')->nullable();

            // The fill itself.
            $table->date('filled_on');
            $table->decimal('quantity', 12, 3);
            $table->string('quantity_unit', 50)->nullable();
            $table->unsignedInteger('days_supply')->nullable();
            $table->string('rx_number', 64)->nullable();
            $table->unsignedInteger('refills_remaining')->nullable();
            $table->text('directions')->nullable();
            $table->string('prescriber_name', 255)->nullable();

            // Price and pharmacy. Integer cents, USD.
            $table->unsignedBigInteger('paid_cents')->nullable();
            $table->unsignedBigInteger('retail_cents')->nullable();
            $table->string('pharmacy_name', 255)->nullable();
            $table->string('pharmacy_location', 255)->nullable();

            $table->string('source_kind', 32)->default('manual');
            $table->string('source_locator', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('retracted_at')->nullable();

            $table->foreign('patient_id')->references('id')->on('phr_patients')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('medication_id')->references('id')->on('phr_medications')->nullOnDelete();

            $table->unique(['patient_id', 'import_source', 'external_id'], 'phr_fills_imp_uid');
            $table->index(['patient_id', 'filled_on'], 'phr_fills_patient_filled_idx');
            $table->index(['patient_id', 'deleted_at', 'retracted_at'], 'phr_medication_fills_lifecycle_idx');
        });

        // External IDs are opaque, case-sensitive identifiers (see
        // 2026_08_22_120000_make_agent_external_id_binary). SQLite is already
        // bytewise; only the MySQL family needs the override.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `external_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE %s NULL', self::TABLE, self::EXTERNAL_ID_COLLATION));
        }
    }

    public function down(): void
    {
        // Forward-only once a fill exists: dropping the table would destroy
        // dispensing history that cannot be rebuilt from anywhere else.
        if (Schema::hasTable('phr_medication_fills') && DB::table('phr_medication_fills')->exists()) {
            throw new RuntimeException('Refusing to drop phr_medication_fills: it holds prescription fills.');
        }

        Schema::dropIfExists('phr_medication_fills');
    }
};
