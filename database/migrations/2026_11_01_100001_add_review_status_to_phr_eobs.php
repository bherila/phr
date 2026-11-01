<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An EOB written over the agent API waits for a person's review like every other
 * agent-written record (phr#268). Existing rows, and everything the console
 * importers write, are `confirmed`: nothing about them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('phr_eobs', 'review_status')) {
            return;
        }

        Schema::table('phr_eobs', function (Blueprint $table): void {
            $table->string('review_status', 32)->default('confirmed')->after('parser_version');
            $table->index(['patient_id', 'review_status'], 'phr_eobs_patient_review_idx');
            // A digest of the lines an agent wrote, so correcting only a line (a description, say)
            // still changes the EOB's opaque version, which hashes the EOB's own columns.
            $table->char('lines_digest', 64)->nullable()->after('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('phr_eobs', function (Blueprint $table): void {
            $table->dropIndex('phr_eobs_patient_review_idx');
            $table->dropColumn(['review_status', 'lines_digest']);
        });
    }
};
