<?php

use App\Support\PHR\PhrMedicationCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A medication's setting and the order details a hospital list carries (issue #413):
 *
 * - `category`: FHIR medicationrequest-category (inpatient, outpatient, community,
 *   discharge); null is unknown. See PhrMedicationCategory.
 * - `as_needed` and `as_needed_reason`: taken as needed (PRN), and for what.
 * - `brand_name`, `rate` (a continuous infusion's rate, "75 mL/h").
 * - `started_at` / `ended_at`: an order's times, beside the existing dates.
 * - `review_flags`: why a person should look closely at an imported row (a
 *   MedicationRequest that may be a MedicationStatement already on the list).
 *
 * Existing rows a person entered, rows prescription fills created, and C-CDA rows the
 * import stands by (not retracted: since #406 only a Medications section writes them)
 * are home medications and become `community`. Every other row stays unknown: nothing
 * else says which list it came from. PhrMedicationCategory::backfilled() states the
 * same rule for a native backup written before the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_medications', function (Blueprint $table): void {
            $table->string('category', 16)->nullable();
            $table->boolean('as_needed')->nullable();
            $table->string('as_needed_reason', 255)->nullable();
            $table->string('brand_name', 255)->nullable();
            $table->string('rate', 100)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->json('review_flags')->nullable();
        });

        DB::table('phr_medications')
            ->whereNull('category')
            ->where(function (Builder $home): void {
                $home->whereNull('import_source')
                    ->orWhere('import_source', PhrMedicationCategory::FILL_IMPORT_SOURCE)
                    ->orWhere(function (Builder $ccda): void {
                        $ccda->where('import_source', PhrMedicationCategory::CCDA_IMPORT_SOURCE)->whereNull('retracted_at');
                    });
            })
            ->update(['category' => PhrMedicationCategory::COMMUNITY]);
    }

    public function down(): void
    {
        Schema::table('phr_medications', function (Blueprint $table): void {
            $table->dropColumn([
                'category',
                'as_needed',
                'as_needed_reason',
                'brand_name',
                'rate',
                'started_at',
                'ended_at',
                'review_flags',
            ]);
        });
    }
};
