<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phr_eobs', function (Blueprint $table): void {
            $table->string('claim_fingerprint', 64)->nullable()->after('external_id');
            $table->unique(
                ['patient_id', 'import_source', 'claim_fingerprint'],
                'phr_eobs_claim_fingerprint_uid'
            );
        });
    }

    public function down(): void
    {
        Schema::table('phr_eobs', function (Blueprint $table): void {
            $table->dropUnique('phr_eobs_claim_fingerprint_uid');
            $table->dropColumn('claim_fingerprint');
        });
    }
};
