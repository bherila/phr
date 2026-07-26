<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinus Sentinel: per-event loudness, plus false-positive / recharacterisation
 * bookkeeping.
 *
 * Loudness columns are dBFS (<= 0 for real signal; validated at -120..20 to
 * leave headroom for clipping and for the adaptive noise floor).
 *
 * A false positive is a misdetection and is excluded from counts. A correction
 * is a real event under the wrong label — it keeps counting, but under
 * `corrected_to_event_type`. The two are deliberately separate columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_respiratory_events', function (Blueprint $table): void {
            $table->float('peak_dbfs')->nullable()->after('confidence');
            $table->float('mean_dbfs')->nullable()->after('peak_dbfs');
            $table->float('noise_floor_dbfs')->nullable()->after('mean_dbfs');

            $table->dateTime('false_positive_at')->nullable()->after('model_version');
            $table->string('corrected_to_event_type', 32)->nullable()->after('false_positive_at');
            $table->dateTime('corrected_at')->nullable()->after('corrected_to_event_type');

            // Reads filter on `false_positive_at IS NULL`. MySQL identifiers cap
            // at 64 chars, so the name is given explicitly rather than generated.
            $table->index(['phr_patient_id', 'false_positive_at'], 'phr_resp_evt_patient_fp_ix');
        });
    }

    public function down(): void
    {
        Schema::table('phr_respiratory_events', function (Blueprint $table): void {
            $table->dropIndex('phr_resp_evt_patient_fp_ix');
            $table->dropColumn([
                'peak_dbfs',
                'mean_dbfs',
                'noise_floor_dbfs',
                'false_positive_at',
                'corrected_to_event_type',
                'corrected_at',
            ]);
        });
    }
};
