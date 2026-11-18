<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EhiOccurrenceDispositionIndexTest extends TestCase
{
    public function test_member_reconciliation_uses_a_covering_index_without_changing_counts_or_rows(): void
    {
        // An isolated minimal SQLite schema exercises just this migration. Full
        // dump/migration parity and PostgreSQL application suites stay in CI.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        DB::purge('sqlite');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('phr_ehi_occurrences', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->unsignedInteger('line_number');
            $table->string('disposition');
            $table->unique(['member_id', 'line_number'], 'phr_ehi_occurrences_member_line_uq');
        });
        DB::table('phr_ehi_occurrences')->insert([
            ['member_id' => 1, 'line_number' => 1, 'disposition' => 'projected'],
            ['member_id' => 1, 'line_number' => 2, 'disposition' => 'projected'],
            ['member_id' => 1, 'line_number' => 3, 'disposition' => 'quarantined'],
            ['member_id' => 2, 'line_number' => 1, 'disposition' => 'retained'],
        ]);
        $before = DB::table('phr_ehi_occurrences')->orderBy('id')->get()->all();
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $query = DB::table('phr_ehi_occurrences')->where('member_id', 1)
            ->selectRaw('disposition, count(*) as aggregate')->groupBy('disposition');
        self::assertSame(['projected' => 2, 'quarantined' => 1], $query->pluck('aggregate', 'disposition')->all());
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings());
        $details = implode("\n", array_column($plan, 'detail'));
        self::assertStringContainsString('USING COVERING INDEX phr_ehi_occurrences_member_disp_idx', $details);
        self::assertStringNotContainsString('TEMP B-TREE', $details);
        self::assertEquals($before, DB::table('phr_ehi_occurrences')->orderBy('id')->get()->all());

        $migration->down();
        $migration->down();
        self::assertFalse(Schema::hasIndex('phr_ehi_occurrences', 'phr_ehi_occurrences_member_disp_idx'));
        self::assertTrue(Schema::hasIndex('phr_ehi_occurrences', 'phr_ehi_occurrences_member_line_uq'));
        self::assertEquals($before, DB::table('phr_ehi_occurrences')->orderBy('id')->get()->all());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_18_000000_index_ehi_member_disposition_counts.php');
    }
}
