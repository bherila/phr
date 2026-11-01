<?php

namespace Tests\Unit\Services\PHR\Ndc;

use App\Services\PHR\Ndc\NdcCandidate;
use App\Services\PHR\Ndc\RxNavListing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Reading RxNav's responses into the same drug fields, casing and units as the openFDA path. */
final class RxNavListingTest extends TestCase
{
    /**
     * @param  list<string>  $scdc
     * @return array<string, mixed>
     */
    private function bundle(string $conceptName, array $scdc, string $form, array $properties = [], string $active = 'NO', array $in = []): array
    {
        $concepts = static fn (array $names): array => array_map(static fn (string $name): array => ['rxcui' => '1', 'name' => $name], $names);

        return [
            'rxcui' => '111',
            'fetched_at' => '2026-11-01T00:00:00+00:00',
            'ndcstatus' => ['ndcStatus' => ['rxnormNdc' => 'YES', 'rxcui' => '111', 'conceptStatus' => 'ACTIVE', 'active' => $active, 'conceptName' => $conceptName]],
            'ndcproperties' => ['ndcPropertyList' => ['ndcProperty' => [[
                'rxcui' => '111', 'ndc9' => '1234-5678',
                'packagingList' => ['packaging' => ['30 TABLET in 1 BOTTLE (1234-5678-90)']],
                'propertyConceptList' => ['propertyConcept' => array_map(static fn (string $name, string $value): array => ['propName' => $name, 'propValue' => $value], array_keys($properties), array_values($properties))],
            ]]]],
            'related' => ['relatedGroup' => ['conceptGroup' => [
                ['tty' => 'DF', 'conceptProperties' => $concepts([$form])],
                ['tty' => 'IN', 'conceptProperties' => $concepts($in)],
                ['tty' => 'SCDC', 'conceptProperties' => $concepts($scdc)],
            ]]],
        ];
    }

    public function test_ingredients_follow_the_concept_name_order_with_their_own_strengths(): void
    {
        // RxNav lists the strength concepts by RxCUI, not in the order the product name writes them.
        $bundle = $this->bundle('hydrochlorothiazide 12.5 MG / lisinopril 10 MG Oral Tablet', ['lisinopril 10 MG', 'hydrochlorothiazide 12.5 MG'], 'Oral Tablet');

        $this->assertSame([
            ['name' => 'hydrochlorothiazide', 'strength' => '12.5 MG'],
            ['name' => 'lisinopril', 'strength' => '10 MG'],
        ], RxNavListing::ingredients($bundle));
    }

    public function test_a_number_inside_an_ingredient_name_stays_in_the_name(): void
    {
        $bundle = $this->bundle('polysorbate 80 10 MG/ML Oral Solution', ['polysorbate 80 10 MG/ML', 'insulin, regular, human 100 UNT/ML', 'epinephrine 0.5 MG/0.1 ML'], 'Oral Solution');

        $this->assertSame([
            ['name' => 'polysorbate 80', 'strength' => '10 MG/ML'],
            ['name' => 'insulin, regular, human', 'strength' => '100 UNT/ML'],
            ['name' => 'epinephrine', 'strength' => '0.5 MG/0.1 ML'],
        ], RxNavListing::ingredients($bundle));
    }

    public function test_without_strength_concepts_the_bare_ingredients_are_used(): void
    {
        $bundle = $this->bundle('some kit', [], 'Kit', in: ['lidocaine', 'epinephrine']);

        $this->assertSame([['name' => 'lidocaine', 'strength' => null], ['name' => 'epinephrine', 'strength' => null]], RxNavListing::ingredients($bundle));
    }

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function doseForms(): iterable
    {
        yield 'route and form' => ['Oral Tablet', 'tablet', 'oral'];
        yield 'extended release' => ['Extended Release Oral Tablet', 'tablet, extended release', 'oral'];
        yield 'delayed release capsule' => ['Delayed Release Oral Capsule', 'capsule, delayed release', 'oral'];
        yield 'disintegrating' => ['Disintegrating Oral Tablet', 'tablet, orally disintegrating', 'oral'];
        yield 'topical' => ['Topical Cream', 'cream', 'topical'];
        yield 'no route word' => ['Injectable Solution', 'injectable solution', null];
        yield 'chewable has no route' => ['Chewable Tablet', 'tablet, chewable', null];
        yield 'ophthalmic' => ['Ophthalmic Solution', 'solution', 'ophthalmic'];
    }

    #[DataProvider('doseForms')]
    public function test_the_dose_form_is_split_into_form_and_route(string $rxnorm, ?string $form, ?string $route): void
    {
        $this->assertSame([$form, $route], RxNavListing::doseFormAndRoute($this->bundle('x 1 MG', ['x 1 MG'], $rxnorm)));
    }

    public function test_a_generic_has_no_brand_and_a_brand_product_does(): void
    {
        $this->assertNull(RxNavListing::brandName($this->bundle('atorvastatin 10 MG Oral Tablet', ['atorvastatin 10 MG'], 'Oral Tablet')));
        $this->assertSame('Lipitor', RxNavListing::brandName($this->bundle('atorvastatin 10 MG Oral Tablet [Lipitor]', ['atorvastatin 10 MG'], 'Oral Tablet')));
    }

    public function test_discontinued_follows_the_marketing_status_else_the_ndc_activity(): void
    {
        $form = 'Oral Tablet';
        $this->assertTrue(RxNavListing::isDiscontinued($this->bundle('x', ['x 1 MG'], $form, ['MARKETING_STATUS' => 'COMPLETED'], 'YES')));
        $this->assertTrue(RxNavListing::isDiscontinued($this->bundle('x', ['x 1 MG'], $form, ['MARKETING_STATUS' => 'Deleted'], 'YES')));
        $this->assertFalse(RxNavListing::isDiscontinued($this->bundle('x', ['x 1 MG'], $form, ['MARKETING_STATUS' => 'Active'], 'NO')));
        $this->assertFalse(RxNavListing::isDiscontinued($this->bundle('x', ['x 1 MG'], $form, ['MARKETING_STATUS' => 'ACTIVE'], 'NO')));
        $this->assertTrue(RxNavListing::isDiscontinued($this->bundle('x', ['x 1 MG'], $form, [], 'NO')), 'no marketing status: the NDC is inactive in RxNorm');
        $this->assertFalse(RxNavListing::isDiscontinued($this->bundle('x', ['x 1 MG'], $form, [], 'YES')));
        $this->assertFalse(RxNavListing::isDiscontinued(null));
    }

    public function test_the_rxcui_in_use_is_the_current_concept_else_the_one_the_ndc_moved_to(): void
    {
        $status = static fn (array $overrides): array => ['ndcStatus' => [...['rxnormNdc' => 'YES', 'rxcui' => '5', 'conceptStatus' => 'ACTIVE'], ...$overrides]];

        $this->assertSame('5', RxNavListing::usableRxcui($status([])));
        $this->assertSame('9', RxNavListing::usableRxcui($status(['conceptStatus' => 'NOTCURRENT', 'ndcHistory' => [
            ['activeRxcui' => '7', 'startDate' => '200701', 'endDate' => '201001'],
            ['activeRxcui' => '9', 'startDate' => '201002', 'endDate' => '202001'],
        ]])));
        $this->assertNull(RxNavListing::usableRxcui($status(['conceptStatus' => 'NOTCURRENT', 'ndcHistory' => [['activeRxcui' => '', 'startDate' => '201301']]])));
        $this->assertNull(RxNavListing::usableRxcui($status(['rxnormNdc' => 'NO'])), 'an NDC RxNorm does not map is not used');
        $this->assertNull(RxNavListing::usableRxcui($status(['rxcui' => ''])));
        $this->assertNull(RxNavListing::usableRxcui([]));
    }

    public function test_the_candidate_uses_the_same_casing_and_units_as_the_openfda_path(): void
    {
        $bundle = $this->bundle(
            'LEVOTHYROXINE 25 MCG / INSULIN 100 UNT/ML Oral Tablet',
            ['LEVOTHYROXINE 25 MCG', 'INSULIN 100 UNT/ML'],
            'Oral Tablet',
            ['LABELER' => 'REMEDYREPACK INC.', 'MARKETING_STATUS' => 'COMPLETED'],
        );

        $candidate = NdcCandidate::fromRxNav('1234-5678-90', $bundle);

        $this->assertSame(['levothyroxine', 'insulin'], $candidate->drug['substance_names']);
        $this->assertSame(['25 mcg', '100 unit/mL'], $candidate->drug['strengths']);
        $this->assertSame('Remedyrepack Inc.', $candidate->drug['labeler_name']);
        $this->assertSame('30 tablet in 1 bottle (1234-5678-90)', $candidate->drug['package_description']);
        $this->assertSame('01234567890', $candidate->ndc11);
        $this->assertSame('rxnav', $candidate->toArray()['ndc_source']);
        $this->assertTrue($candidate->toArray()['discontinued']);
        $this->assertSame('1234-5678', $candidate->productNdc());
        $this->assertSame('rxnav', $candidate->fillAttributes()['ndc_source']);
        $this->assertSame('rxnav', $candidate->fillAttributes()['fda_snapshot']['source']);
    }
}
