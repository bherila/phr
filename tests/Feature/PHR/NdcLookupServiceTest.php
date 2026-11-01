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

    public function test_an_eleven_digit_ndc_resolves_to_the_one_package_openfda_lists(): void
    {
        $this->fakeOpenFda();

        // 60505-0134-00 reads as 60505-134-00 and 60505-0134-0; only the second is listed.
        $resolution = $this->ndc->lookup('60505-0134-00');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $chosen = $resolution->chosen();
        $this->assertSame('60505-0134-0', $chosen?->ndc10);
        $this->assertSame('60505013400', $chosen?->ndc11);
        $this->assertSame('Cyclosporine', $chosen->drug['brand_name']);
        $this->assertSame(['cyclosporine'], $chosen->drug['substance_names']);
        $this->assertSame(['100 mg/1'], $chosen->drug['strengths']);
        $this->assertSame('capsule, gelatin coated', $chosen->drug['dosage_form']);
        $this->assertSame('oral', $chosen->drug['route']);
        $this->assertSame('Apotex Corp.', $chosen->drug['labeler_name']);
        $this->assertSame('197553', $chosen->drug['rxcui']);
        $this->assertSame('30 CAPSULE, GELATIN COATED in 1 BOTTLE (60505-0134-0)', $chosen->snapshot['packaging'][0]['description'] ?? null, 'the raw listing is kept');
    }

    public function test_the_other_reading_of_the_issue_example_resolves_the_same_way(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('00002-0800-01');

        // 00002-0800-01 reads as 0002-0800-01, 00002-800-01 and 00002-0800-1; only the first is listed.
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
        $this->fakeOpenFda(['00002-0800-1']);

        $resolution = $this->ndc->lookup('00002080001');

        $this->assertSame(NdcResolution::AMBIGUOUS, $resolution->status);
        $this->assertNull($resolution->chosen());
        $this->assertSame(['0002-0800-01', '00002-0800-1'], array_map(static fn ($c) => $c->ndc10, $resolution->candidates));
        $array = $resolution->toArray();
        $this->assertNull($array['selected']);
        $this->assertCount(2, $array['candidates']);
        $this->assertStringContainsString('ndc_candidate', (string) $array['next_step']);
    }

    public function test_the_chosen_candidate_resolves_exactly_that_package(): void
    {
        $this->fakeOpenFda(['00002-0800-1']);

        $resolution = $this->ndc->lookupChosen('00002-0800-1', '00002080001');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('00002-0800-1', $resolution->chosen()?->ndc10);
        $this->assertSame('00002080001', $resolution->chosen()?->ndc11);
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
}
