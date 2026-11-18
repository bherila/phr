<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string INDEX = 'phr_ehi_occurrences_member_disp_idx';

    public function up(): void
    {
        if (Schema::hasTable('phr_ehi_occurrences') && ! Schema::hasIndex('phr_ehi_occurrences', self::INDEX)) {
            Schema::table('phr_ehi_occurrences', static function (Blueprint $table): void {
                // Each reviewed proposal reconciles its member's disposition
                // counts. Cover the member filter and grouping together rather
                // than fetching every occurrence through member/line order.
                $table->index(['member_id', 'disposition'], self::INDEX); // sensitive-scan-ignore: schema column names, not an insurance identifier
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('phr_ehi_occurrences') && Schema::hasIndex('phr_ehi_occurrences', self::INDEX)) {
            Schema::table('phr_ehi_occurrences', static function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
