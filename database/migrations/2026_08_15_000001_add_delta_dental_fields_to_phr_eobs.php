<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_eobs', function (Blueprint $table): void {
            $table->string('provider_phone', 50)->nullable()->after('provider_name');
            $table->date('submission_date')->nullable()->after('check_amount');
            $table->decimal('total_accepted_fee', 14, 2)->nullable()->after('processed_date');
            $table->index(['patient_id', 'submission_date'], 'phr_eobs_patient_submission_idx');
        });

        Schema::table('phr_eob_lines', function (Blueprint $table): void {
            $table->decimal('accepted_fee', 14, 2)->nullable()->after('service_end');
        });
    }

    public function down(): void
    {
        Schema::table('phr_eob_lines', function (Blueprint $table): void {
            $table->dropColumn('accepted_fee');
        });

        Schema::table('phr_eobs', function (Blueprint $table): void {
            $table->dropIndex('phr_eobs_patient_submission_idx');
            $table->dropColumn(['provider_phone', 'submission_date', 'total_accepted_fee']);
        });
    }
};
