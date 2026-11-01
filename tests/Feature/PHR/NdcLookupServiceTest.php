<?php

namespace Tests\Feature\PHR;

use App\Services\PHR\Ndc\NdcLookupService;
use App\Services\PHR\Ndc\NdcResolution;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** Lookup and search against recorded openFDA responses; the live API is never called. */
final class NdcLookupServiceTest extends TestCase
{
    use FakesOpenFda;

    private NdcLookupService $ndc;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->ndc = app(NdcLookupService::class);
    }

    public function test_an_eleven_digit_ndc_is_converted_by_the_fixed_rule_and_only_that_package_is_looked_up(): void
    {
        $this->fakeOpenFda();

        // 60505-0134-00 becomes 60505-134-00 (middle segment first); the fixture lists it under that code.
        $resolution = $this->ndc->lookup('60505-0134-00');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $chosen = $resolution->chosen();
        $this->assertSame('60505-134-00', $chosen?->ndc10);
        $this->assertSame('60505013400', $chosen?->ndc11);
        $this->assertSame('Cyclosporine', $chosen->drug['brand_name']);
        $this->assertCount(1, $this->openFdaRequests, 'one reading, one request');
        $this->assertStringContainsString('60505-134-00', urldecode($this->openFdaRequests[0]));
    }

    public function test_an_eleven_digit_ndc_never_tries_the_other_readings(): void
    {
        $this->fakeOpenFda();

        // 60505-0134-0 is listed, but the rule reads 60505-0134-00 as 60505-134-00 only.
        $resolution = $this->ndc->lookup('60505013400');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('60505-134-00', $resolution->chosen()?->ndc10);
        $this->assertCount(1, $this->openFdaRequests);

        $this->fakeOpenFda();
        $unlisted = $this->ndc->lookup('12345-6789-01');
        $this->assertSame(NdcResolution::UNRESOLVED, $unlisted->status);
        $this->assertCount(1, $this->openFdaRequests);
    }

    public function test_the_issue_example_converts_by_the_first_segment_rule(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('00002-0800-01');

        // The rule drops the first segment's leading 0: 00002-0800-01 becomes 0002-0800-01.
        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('0002-0800-01', $resolution->chosen()?->ndc10);
        $this->assertSame('00002080001', $resolution->chosen()?->ndc11);
    }

    public function test_ten_digits_without_hyphens_try_all_three_layouts(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('6050501340');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('60505-0134-0', $resolution->chosen()?->ndc10);
        $this->assertSame('60505013400', $resolution->chosen()->ndc11);
        $this->assertSame(['cyclosporine'], $resolution->chosen()->drug['substance_names']);
        $this->assertSame(['100 mg/1'], $resolution->chosen()->drug['strengths']);
        $this->assertSame('capsule, gelatin coated', $resolution->chosen()->drug['dosage_form']);
        $this->assertSame('30 CAPSULE, GELATIN COATED in 1 BOTTLE (60505-0134-0)', $resolution->chosen()->snapshot['packaging'][0]['description'] ?? null, 'the raw listing is kept');
        $this->assertCount(3, $this->openFdaRequests);
    }

    public function test_a_five_three_two_package_resolves(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('68180051801');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('68180-518-01', $resolution->chosen()?->ndc10);
        $this->assertSame(['hydrochlorothiazide', 'lisinopril'], $resolution->chosen()->drug['substance_names'], 'a combination drug has several');
        $this->assertSame(['12.5 mg/1', '10 mg/1'], $resolution->chosen()->drug['strengths']);
    }

    public function test_several_listed_packages_are_ambiguous_and_all_returned(): void
    {
        $this->fakeOpenFda(['00020-800-01']);

        // Ten digits without hyphens have no known layout: 0002-0800-01, 00020-800-01 and 00020-8000-1.
        $resolution = $this->ndc->lookup('0002080001');

        $this->assertSame(NdcResolution::AMBIGUOUS, $resolution->status);
        $this->assertNull($resolution->chosen());
        $this->assertSame(['0002-0800-01', '00020-800-01'], array_map(static fn ($c) => $c->ndc10, $resolution->candidates));
        $array = $resolution->toArray();
        $this->assertNull($array['selected']);
        $this->assertCount(2, $array['candidates']);
        $this->assertStringContainsString('ndc_candidate', (string) $array['next_step']);
    }

    public function test_the_chosen_candidate_resolves_exactly_that_package(): void
    {
        $this->fakeOpenFda(['00020-800-01']);

        $resolution = $this->ndc->lookupChosen('00020-800-01', '0002080001');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('00020-800-01', $resolution->chosen()?->ndc10);
        $this->assertSame('00020080001', $resolution->chosen()?->ndc11);
    }

    public function test_an_unlisted_ndc_is_unresolved_and_a_discontinued_one_drops_out(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('12345-6789-0');

        $this->assertSame(NdcResolution::UNRESOLVED, $resolution->status);
        $this->assertSame(NdcResolution::REASON_NOT_FOUND, $resolution->reason);
        $this->assertSame([], $resolution->candidates);
    }

    public function test_input_that_is_not_an_ndc_never_reaches_openfda(): void
    {
        Http::fake();

        $resolution = $this->ndc->lookup('not an ndc');

        $this->assertSame(NdcResolution::UNRESOLVED, $resolution->status);
        $this->assertSame(NdcResolution::REASON_INVALID, $resolution->reason);
        Http::assertNothingSent();
    }

    public function test_an_outage_is_never_reported_as_not_found(): void
    {
        $this->fakeOpenFda(down: true);

        $resolution = $this->ndc->lookup('58151-0155-77');

        $this->assertSame(NdcResolution::UNRESOLVED, $resolution->status);
        $this->assertSame(NdcResolution::REASON_UNAVAILABLE, $resolution->reason);
        $this->assertStringContainsString('Try again', (string) $resolution->toArray()['next_step']);
    }

    public function test_package_answers_are_cached_by_package_ndc(): void
    {
        $this->fakeOpenFda();

        $this->ndc->lookup('58151-0155-77');
        $first = count($this->openFdaRequests);
        $this->ndc->lookup('58151-0155-77');
        $this->assertSame(1, $first);
        $this->assertSame(1, count($this->openFdaRequests), 'the repeat came from the cache');

        $this->ndc->lookup('6050501340');
        $this->ndc->lookup('6050501340');
        $this->assertSame(1 + 3, count($this->openFdaRequests), 'three readings fetched once, then cached (including the misses)');
    }

    public function test_the_api_key_and_a_short_timeout_come_from_config(): void
    {
        config(['services.openfda.key' => 'synthetic-key', 'services.openfda.timeout' => 2]);
        $this->fakeOpenFda();

        $this->ndc->lookup('58151-0155-77');

        $this->assertStringContainsString('api_key=synthetic-key', $this->openFdaRequests[0]);
        $this->assertStringStartsWith('https://api.fda.gov/drug/ndc.json?', $this->openFdaRequests[0]);
    }

    public function test_no_key_means_an_unauthenticated_request(): void
    {
        config(['services.openfda.key' => null]);
        $this->fakeOpenFda();

        $this->ndc->lookup('58151-0155-77');

        $this->assertStringNotContainsString('api_key', $this->openFdaRequests[0]);
    }

    public function test_search_ranks_candidates_with_match_reasons_and_never_picks_one(): void
    {
        $this->fakeOpenFda();

        $result = $this->ndc->search([
            'name' => 'atorvastatin', 'strength' => '10mg', 'dosage_form' => 'tablet, film coated',
            'route' => 'oral', 'labeler' => 'viatris', 'package_size' => '90',
        ]);

        $this->assertSame('candidates', $result['status']);
        $top = $result['candidates'][0];
        $this->assertSame('58151-155-77', $top['ndc_10']);
        $this->assertSame('Lipitor', $top['drug']['brand_name']);
        $this->assertContains('strength matches', $top['match_reasons']);
        $this->assertContains('labeler matches', $top['match_reasons']);
        $this->assertContains('package size matches', $top['match_reasons']);
        $this->assertContains('dosage form matches', $top['match_reasons']);
        $scores = array_column($result['candidates'], 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'candidates are ranked best first');
        $this->assertGreaterThan(1, count($result['candidates']), 'several candidates are returned, none chosen');
        $this->assertArrayNotHasKey('selected', $result);
        $this->assertSame('Viatris Specialty LLC', $top['drug']['labeler_name']);
        // The caps labeler of a repackager came back title-cased.
        $names = array_column(array_column($result['candidates'], 'drug'), 'brand_name');
        $this->assertContains('Atorvastatin Calcium', $names, 'an ALL-CAPS brand is title-cased');
    }

    public function test_search_sends_only_drug_descriptors_to_openfda(): void
    {
        $this->fakeOpenFda();

        $this->ndc->search(['name' => 'atorvastatin', 'strength' => '10 mg', 'labeler' => 'Viatris', 'package_size' => '90']);

        $query = urldecode($this->openFdaRequests[0]);
        $this->assertStringContainsString('brand_name:"atorvastatin"', $query);
        $this->assertStringContainsString('active_ingredients.strength:"10 mg/1"', $query);
        $this->assertStringContainsString('labeler_name:"Viatris"', $query);
        $this->assertStringContainsString('packaging.description:"90"', $query);
    }

    public function test_search_needs_a_name_and_reports_an_outage(): void
    {
        $this->fakeOpenFda(down: true);

        $this->assertSame('unresolved', $this->ndc->search(['name' => 'atorvastatin'])['status']);
        $this->assertSame(NdcResolution::REASON_UNAVAILABLE, $this->ndc->search(['name' => 'atorvastatin'])['reason']);
    }

    public function test_openfda_is_tried_twice_on_a_transient_error_and_never_on_a_miss(): void
    {
        Http::fake(['api.fda.gov/*' => Http::sequence()->push(['error' => 'busy'], 503)->push($this->fixture('package-58151-155-77.json'))]);

        $this->assertSame(NdcResolution::RESOLVED, $this->ndc->lookup('58151-155-77')->status);
        Http::assertSentCount(2);

        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['api.fda.gov/*' => Http::response(['error' => 'busy'], 503)]);
        $this->assertSame(NdcResolution::REASON_UNAVAILABLE, $this->ndc->lookup('58151-155-77')->reason);
        Http::assertSentCount(2);

        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['api.fda.gov/*' => Http::response($this->fixture('not-found.json'), 404)]);
        $this->assertSame(NdcResolution::REASON_NOT_FOUND, $this->ndc->lookup('58151-155-77')->reason);
        Http::assertSentCount(1);
    }

    public function test_search_loosens_step_by_step_from_every_filter_to_name_and_strength_to_name(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $seen[] = urldecode((string) $query['search']);

            return count($seen) < 3 ? Http::response($this->fixture('not-found.json'), 404) : Http::response($this->fixture('search-atorvastatin.json'));
        });

        $result = $this->ndc->search(['name' => 'atorvastatin', 'strength' => '10 mg', 'labeler' => 'Viatris', 'dosage_form' => 'TAB']);

        $this->assertSame('candidates', $result['status']);
        $this->assertCount(3, $seen);
        $this->assertStringContainsString('labeler_name:"Viatris"', $seen[0]);
        $this->assertStringContainsString('dosage_form:"TAB"', $seen[0]);
        $this->assertStringContainsString('active_ingredients.strength', $seen[1]);
        $this->assertStringNotContainsString('labeler_name', $seen[1]);
        $this->assertStringNotContainsString('strength', $seen[2]);
        $this->assertStringContainsString('brand_name:"atorvastatin"', $seen[2]);
    }

    public function test_search_without_a_strength_loosens_straight_to_the_name_and_stops_at_the_first_hit(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $seen[] = urldecode((string) $query['search']);

            return count($seen) < 2 ? Http::response($this->fixture('not-found.json'), 404) : Http::response($this->fixture('search-atorvastatin.json'));
        });

        $this->ndc->search(['name' => 'atorvastatin', 'labeler' => 'Viatris']);
        $this->assertCount(2, $seen);

        // A name-only search is one request, not two identical ones.
        $seen = [];
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response($this->fixture('not-found.json'), 404);
        });
        $this->assertSame('unresolved', $this->ndc->search(['name' => 'nothing'])['status']);
        $this->assertCount(1, $seen);
    }

    public function test_search_results_are_cached_briefly_but_an_outage_is_not(): void
    {
        $this->fakeOpenFda();
        $criteria = ['name' => 'atorvastatin', 'strength' => '10 mg'];

        $first = $this->ndc->search($criteria);
        $requests = count($this->openFdaRequests);
        $this->assertSame($first, $this->ndc->search($criteria));
        $this->assertSame($requests, count($this->openFdaRequests), 'the repeat came from the cache');
        $this->ndc->search([...$criteria, 'strength' => '20 mg']);
        $this->assertGreaterThan($requests, count($this->openFdaRequests), 'other criteria are another search');

        Cache::flush();
        $this->fakeOpenFda(down: true);
        $this->ndc->search($criteria);
        $this->fakeOpenFda();
        $this->assertSame('candidates', $this->ndc->search($criteria)['status'], 'the outage was not remembered');
    }

    public function test_name_only_fallback_never_ranks_25_mg_or_5_mg_per_ml_as_a_strength_match(): void
    {
        $product = fn (string $ndc, string $strength): array => [
            'product_ndc' => $ndc, 'brand_name' => 'Synthetic', 'generic_name' => 'synthetic', 'labeler_name' => 'Example Labs',
            'dosage_form' => 'TABLET', 'route' => ['ORAL'], 'active_ingredients' => [['name' => 'SYNTHETIC', 'strength' => $strength]],
            'packaging' => [['package_ndc' => $ndc.'-1', 'description' => '30 TABLET in 1 BOTTLE ('.$ndc.'-1)']], 'openfda' => [],
        ];
        // Only the name-only step finds anything, as when the strength filter is too strict.
        Http::fake(function ($request) use ($product) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (str_contains((string) $query['search'], 'strength')) {
                return Http::response($this->fixture('not-found.json'), 404);
            }

            return Http::response(['meta' => [], 'results' => [
                $product('11111-111', '25 mg/1'), $product('22222-222', '2.5 mg/1'),
                $product('33333-333', '5 mg/mL'), $product('44444-444', '0.5 mg/mL'),
            ]]);
        });

        $small = $this->ndc->search(['name' => 'synthetic', 'strength' => '2.5 mg'])['candidates'];
        $byNdc = array_column($small, null, 'ndc_10');
        $this->assertContains('strength matches', $byNdc['22222-222-1']['match_reasons']);
        $this->assertNotContains('strength matches', $byNdc['11111-111-1']['match_reasons']);
        $this->assertSame('22222-222-1', $small[0]['ndc_10'], 'the real 2.5 mg ranks first');

        $liquid = $this->ndc->search(['name' => 'synthetic', 'strength' => '0.5 mg/mL'])['candidates'];
        $byNdc = array_column($liquid, null, 'ndc_10');
        $this->assertContains('strength matches', $byNdc['44444-444-1']['match_reasons']);
        $this->assertNotContains('strength matches', $byNdc['33333-333-1']['match_reasons']);
        $this->assertSame('44444-444-1', $liquid[0]['ndc_10']);
    }
}
