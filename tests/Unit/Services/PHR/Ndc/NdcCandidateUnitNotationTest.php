<?php

namespace Tests\Unit\Services\PHR\Ndc;

use App\Services\PHR\Ndc\NdcCandidate;
use PHPUnit\Framework\TestCase;

final class NdcCandidateUnitNotationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function insulin(): array
    {
        return [
            'product_ndc' => '0002-8798',
            'brand_name' => 'SYNTHETIC INSULIN',
            'dosage_form' => 'INJECTION, SOLUTION',
            'route' => ['SUBCUTANEOUS'],
            'active_ingredients' => [['name' => 'INSULIN LISPRO', 'strength' => '100 [iU]/mL']],
            'packaging' => [['package_ndc' => '0002-8798-59', 'description' => '5 SYRINGE in 1 CARTON (0002-8798-59) / 3 mL in 1 SYRINGE (0002-8798-01)']],
            'openfda' => [],
        ];
    }

    public function test_display_text_is_normalized_and_the_raw_snapshot_is_kept_unchanged(): void
    {
        $product = self::insulin();
        $candidate = NdcCandidate::fromProduct($product, $product['packaging'][0], '2026-11-01T00:00:00Z');

        $this->assertSame(['100 IU/mL'], $candidate->drug['strengths']);
        $this->assertSame('5 syringe in 1 carton (0002-8798-59) / 3 mL in 1 syringe (0002-8798-01)', $candidate->drug['package_description']);
        $this->assertSame($product, $candidate->snapshot);
        $this->assertSame('100 [iU]/mL', $candidate->fillAttributes()['fda_snapshot']['active_ingredients'][0]['strength']);
        $this->assertSame('5 SYRINGE in 1 CARTON (0002-8798-59) / 3 mL in 1 SYRINGE (0002-8798-01)', $candidate->fillAttributes()['fda_snapshot']['packaging'][0]['description']);
    }

    public function test_spacing_and_casing_apply_to_live_strengths(): void
    {
        $product = self::insulin();
        $product['active_ingredients'] = [['name' => 'A', 'strength' => '20 meq/15mL'], ['name' => 'B', 'strength' => '600 mg/5mL'], ['name' => 'C', 'strength' => '10 mg/1']];
        $product['packaging'] = [['package_ndc' => '1-2-3', 'description' => '125 mL in 1 BOTTLE (68071-2852-5)']];
        $candidate = NdcCandidate::fromProduct($product, $product['packaging'][0], 'x');

        $this->assertSame(['20 mEq/15 mL', '600 mg/5 mL', '10 mg/1'], $candidate->drug['strengths']);
        $this->assertSame('125 mL in 1 bottle (68071-2852-5)', $candidate->drug['package_description']);
        $this->assertSame('20 meq/15mL', $candidate->snapshot['active_ingredients'][0]['strength']);
    }
}
