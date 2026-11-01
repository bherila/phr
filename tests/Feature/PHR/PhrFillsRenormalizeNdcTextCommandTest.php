<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PhrFillsRenormalizeNdcTextCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'product_ndc' => '0002-8798',
            'active_ingredients' => [['name' => 'INSULIN LISPRO', 'strength' => '100 [iU]/mL']],
            'packaging' => [['package_ndc' => '0002-8798-59', 'description' => '5 SYRINGE in 1 CARTON (0002-8798-59) / 3 mL in 1 SYRINGE (0002-8798-01)']],
        ];
    }

    public function test_it_rewrites_only_the_two_text_columns_and_is_idempotent(): void
    {
        $fill = PhrMedicationFill::factory()->create([
            'ndc_10' => '0002-8798-59',
            'strengths' => ['100 [iU]/mL'],
            'package_description' => '5 syringe in 1 carton (0002-8798-59) / 3 mL in 1 syringe (0002-8798-01)',
            'fda_snapshot' => $this->snapshot(),
            'review_status' => 'pending_review',
        ]);
        $old = Carbon::parse('2026-01-02 03:04:05');
        DB::table('phr_medication_fills')->where('id', $fill->id)->update(['updated_at' => $old, 'package_description' => '5 SYRINGE in 1 CARTON (0002-8798-59) / 3 mL in 1 SYRINGE (0002-8798-01)']);
        $before = DB::table('phr_medication_fills')->where('id', $fill->id)->first();

        $this->artisan('phr:fills:renormalize-ndc-text', ['--dry-run' => true])->assertSuccessful();
        $this->assertEquals($before, DB::table('phr_medication_fills')->where('id', $fill->id)->first());

        $this->artisan('phr:fills:renormalize-ndc-text')->assertSuccessful();
        $after = DB::table('phr_medication_fills')->where('id', $fill->id)->first();
        $this->assertSame(['100 IU/mL'], json_decode($after->strengths, true));
        $this->assertSame('5 syringe in 1 carton (0002-8798-59) / 3 mL in 1 syringe (0002-8798-01)', $after->package_description);
        $this->assertSame($before->updated_at, $after->updated_at);
        $this->assertSame('pending_review', $after->review_status);
        $this->assertSame($before->fda_snapshot, $after->fda_snapshot);
        $changed = array_keys(array_diff_assoc((array) $after, (array) $before));
        sort($changed);
        $this->assertSame(['package_description', 'strengths'], $changed);

        $this->artisan('phr:fills:renormalize-ndc-text')->assertSuccessful();
        $this->assertEquals($after, DB::table('phr_medication_fills')->where('id', $fill->id)->first());
    }

    public function test_fills_without_a_snapshot_are_left_alone(): void
    {
        $fill = PhrMedicationFill::factory()->create(['strengths' => ['5mL'], 'fda_snapshot' => null]);

        $this->artisan('phr:fills:renormalize-ndc-text')->assertSuccessful();

        $this->assertSame(['5mL'], $fill->fresh()->strengths);
    }
}
