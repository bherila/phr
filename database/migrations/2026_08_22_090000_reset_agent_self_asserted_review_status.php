<?php

use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Returns agent-written records to the review queue.
 *
 * Until now the agent API accepted a client-supplied `review_status`, so an
 * integration could mark its own writes `confirmed`. No browser review route
 * existed before this release, which means every `confirmed` value on an
 * agent-written row was asserted by the agent itself and was never seen by a
 * human. Those rows now feed confirmed-only clinical exports, so they are moved
 * back to `pending_review`.
 *
 * Rows written through the browser carry no agent import source and are left
 * alone, as are rows an agent had already left pending.
 */
return new class extends Migration
{
    private const array TABLES = [
        'phr_office_visits',
        'phr_procedures',
        'phr_immunizations',
        'phr_medications',
        'phr_conditions',
        'phr_allergies',
        'phr_lab_results',
        'phr_patient_vitals',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->where('review_status', PhrReviewStatus::CONFIRMED)
                ->where('import_source', 'like', 'agent-client:%')
                ->update(['review_status' => PhrReviewStatus::PENDING]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible. Reinstating a machine-asserted confirmation
        // would put unreviewed clinical data back into exports, and the original
        // values cannot be distinguished from legitimate pending records anyway.
    }
};
