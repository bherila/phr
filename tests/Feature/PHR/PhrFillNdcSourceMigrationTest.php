<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PhrFillNdcSourceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolved_fills_are_backfilled_openfda_and_others_have_no_source(): void
    {
        $resolved = PhrMedicationFill::factory()->create();
        $unresolved = PhrMedicationFill::factory()->create(['ndc_status' => 'unresolved', 'ndc_source' => null]);
        $rxnav = PhrMedicationFill::factory()->create(['ndc_source' => 'rxnav']);
        DB::table('phr_medication_fills')->where('id', $resolved->id)->update(['ndc_source' => null]);

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertSame('openfda', $resolved->refresh()->ndc_source);
        $this->assertNull($unresolved->refresh()->ndc_source);
        $this->assertSame('rxnav', $rxnav->refresh()->ndc_source, 'a source already recorded is kept');
        $this->assertTrue(Schema::hasColumn('phr_medication_fills', 'ndc_source'));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_01_000005_add_ndc_source_to_phr_medication_fills.php');
    }
}
