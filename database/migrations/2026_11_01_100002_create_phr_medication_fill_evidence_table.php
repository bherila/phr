<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that vouches for one fill beyond the document it was first read
 * from (phr#268): the label, the receipt and the EOB line of one dispensing are
 * one fill with several pieces of evidence. A row points at a document, at the
 * EOB line that adjudicated the fill, or both.
 *
 * Also adds `review_flags` to fills, where a write that could not be sure it
 * was not a duplicate says so for the reviewer.
 *
 * Additive only.
 */
return new class extends Migration
{
    private const string TABLE = 'phr_medication_fill_evidence';

    private const string EXTERNAL_ID_COLLATION = 'utf8mb4_bin';

    public function up(): void
    {
        if (! Schema::hasTable('phr_medication_fill_evidence')) {
            Schema::create('phr_medication_fill_evidence', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('patient_id')->index();
                $table->unsignedBigInteger('fill_id');
                $table->unsignedBigInteger('document_id')->nullable()->index();
                $table->unsignedBigInteger('eob_line_id')->nullable()->index();
                $table->string('source_kind', 32);
                $table->string('source_locator', 255)->nullable();
                $table->char('evidence_key', 40);
                // An alias: the identity a connection used for a fill that turned out to be one the
                // patient already had. It keeps a retry of that write on the same fill, and keeps
                // it from recreating the fill if a person deleted it.
                $table->string('import_source', 50)->nullable();
                $table->string('external_id')->nullable();
                $table->timestamps();

                $table->unique(['fill_id', 'evidence_key'], 'phr_fill_evidence_uid');
                $table->index(['patient_id', 'import_source', 'external_id'], 'phr_fill_evidence_alias_idx');
                $table->foreign('patient_id')->references('id')->on('phr_patients')->cascadeOnDelete();
                $table->foreign('fill_id')->references('id')->on('phr_medication_fills')->cascadeOnDelete();
                $table->foreign('document_id')->references('id')->on('phr_documents')->nullOnDelete();
                $table->foreign('eob_line_id')->references('id')->on('phr_eob_lines')->nullOnDelete();
            });
        }

        // External IDs are opaque, case-sensitive identifiers (see
        // 2026_08_22_120000_make_agent_external_id_binary). SQLite is already bytewise.
        if (Schema::hasTable(self::TABLE) && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `external_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE %s NULL', self::TABLE, self::EXTERNAL_ID_COLLATION));
        }

        if (! Schema::hasColumn('phr_medication_fills', 'review_flags')) {
            Schema::table('phr_medication_fills', function (Blueprint $table): void {
                $table->json('review_flags')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('phr_medication_fill_evidence');
        if (Schema::hasColumn('phr_medication_fills', 'review_flags')) {
            Schema::table('phr_medication_fills', function (Blueprint $table): void {
                $table->dropColumn('review_flags');
            });
        }
    }
};
