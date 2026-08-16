<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_native_record_identities', function (Blueprint $table): void {
            // Archive timestamps remain full-fidelity source data. This separate
            // ingestion watermark lets incremental APIs surface restored rows at
            // the time they became visible without rewriting their clinical dates.
            $table->timestamp('restored_at')->nullable()->after('native_id');
            $table->index(['record_table', 'restored_at'], 'phr_native_identity_restored_idx');
        });
    }

    public function down(): void
    {
        Schema::table('phr_native_record_identities', function (Blueprint $table): void {
            $table->dropIndex('phr_native_identity_restored_idx');
            $table->dropColumn('restored_at');
        });
    }
};
