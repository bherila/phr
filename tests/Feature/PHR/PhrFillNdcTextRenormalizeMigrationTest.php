<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PhrFillNdcTextRenormalizeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'migrations/2026_11_01_000003_run_fill_ndc_text_renormalize.php';

    public function test_the_deploy_migration_renormalizes_saved_fills_once_and_again_changes_nothing(): void
    {
        $fill = PhrMedicationFill::factory()->create([
            'ndc_10' => '0002-8798-59',
            'strengths' => ['100 [iU]/mL'],
            'fda_snapshot' => [
                'product_ndc' => '0002-8798',
                'active_ingredients' => [['name' => 'INSULIN LISPRO', 'strength' => '100 [iU]/mL']],
                'packaging' => [['package_ndc' => '0002-8798-59', 'description' => '5 SYRINGE in 1 CARTON (0002-8798-59) / 3 mL in 1 SYRINGE (0002-8798-01)']],
            ],
        ]);
        DB::table('phr_medication_fills')->where('id', $fill->id)->update(['strengths' => json_encode(['100 [iU]/mL'])]);

        $this->migration()->up();
        $after = DB::table('phr_medication_fills')->where('id', $fill->id)->first();
        $this->assertSame(['100 IU/mL'], json_decode($after->strengths, true));

        $this->migration()->up();
        $this->assertEquals($after, DB::table('phr_medication_fills')->where('id', $fill->id)->first());
        $this->migration()->down();
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
