<?php

namespace Tests\Feature\PHR;

use App\Services\PHR\Ndc\NdcLookupService;
use App\Services\PHR\Ndc\NdcResolution;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** The RxNav fallback for NDCs openFDA does not list, against recorded RxNav responses. */
final class NdcLookupRxNavFallbackTest extends TestCase
{
    use FakesOpenFda;

    private NdcLookupService $ndc;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->ndc = app(NdcLookupService::class);
    }

    public function test_a_discontinued_ndc_openfda_does_not_list_resolves_from_rxnav(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('00093015010');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $chosen = $resolution->chosen();
        $this->assertSame('0093-0150-10', $chosen?->ndc10);
        $this->assertSame('00093015010', $chosen->ndc11);
        $this->assertSame('rxnav', $chosen->source);
        $this->assertTrue($chosen->discontinued);
        $this->assertSame([
            'substance_names' => ['acetaminophen', 'codeine phosphate'],
            'strengths' => ['300 mg', '30 mg'],
            'brand_name' => null,
            'dosage_form' => 'tablet',
            'route' => 'oral',
            'labeler_name' => 'Teva Pharmaceuticals USA, Inc.',
            'package_description' => '1000 tablet in 1 bottle (0093-0150-10)',
            'rxcui' => '993781',
        ], $chosen->drug);
        $this->assertSame('0093-0150', $chosen->productNdc());
        $this->assertCount(1, $this->openFdaRequests, 'openFDA is asked first, once');

        $array = $resolution->toArray();
        $this->assertSame('rxnav', $array['selected']['ndc_source']);
        $this->assertTrue($array['selected']['discontinued']);
        $this->assertNull($array['next_step']);
    }

    public function test_the_raw_rxnav_responses_are_kept_as_the_snapshot(): void
    {
        $this->fakeOpenFda();

        $snapshot = $this->ndc->lookup('00093015010')->chosen()?->snapshot;

        $this->assertSame('rxnav', $snapshot['source']);
        $this->assertSame('OBSOLETE', $snapshot['ndcstatus']['ndcStatus']['status']);
        $this->assertSame('a9b11cbf-7585-4532-868f-5686f028679f', $snapshot['ndcproperties']['ndcPropertyList']['ndcProperty'][0]['splSetIdItem']);
        $this->assertSame('ANDA088628', $snapshot['ndcproperties']['ndcPropertyList']['ndcProperty'][0]['propertyConceptList']['propertyConcept'][0]['propValue']);
        $this->assertSame('317541', $snapshot['related']['relatedGroup']['conceptGroup'][0]['conceptProperties'][0]['rxcui']);
    }

    public function test_a_brand_product_takes_its_brand_name_from_rxnav(): void
    {
        $this->fakeOpenFda();

        $chosen = $this->ndc->lookup('0071-0155-23')->chosen();

        $this->assertSame('Lipitor', $chosen?->drug['brand_name']);
        $this->assertSame(['atorvastatin'], $chosen->drug['substance_names']);
        $this->assertSame(['10 mg'], $chosen->drug['strengths']);
        $this->assertSame('90 tablet, film coated in 1 bottle (0071-0155-23)', $chosen->drug['package_description']);
        $this->assertSame('617314', $chosen->drug['rxcui']);
        $this->assertTrue($chosen->discontinued, 'RxNav\'s SPL marketing status is COMPLETED');
    }

    public function test_an_openfda_hit_never_calls_rxnav(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('58151-0155-77');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('openfda', $resolution->chosen()?->source);
        $this->assertFalse($resolution->chosen()->discontinued);
        $this->assertSame([], $this->rxnavRequests);
        $this->assertSame('openfda', $resolution->toArray()['selected']['ndc_source']);
        $this->assertFalse($resolution->toArray()['selected']['discontinued']);
    }

    public function test_openfda_unavailable_never_calls_rxnav(): void
    {
        $this->fakeOpenFda(down: true);

        $resolution = $this->ndc->lookup('00093015010');

        $this->assertSame(NdcResolution::UNRESOLVED, $resolution->status);
        $this->assertSame(NdcResolution::REASON_UNAVAILABLE, $resolution->reason);
        $this->assertSame([], $this->rxnavRequests);
    }

    public function test_an_ndc_neither_directory_lists_stays_unresolved_with_the_current_guidance(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookup('12345-6789-01');

        $this->assertSame(NdcResolution::UNRESOLVED, $resolution->status);
        $this->assertSame(NdcResolution::REASON_NOT_FOUND, $resolution->reason);
        $this->assertStringContainsString('ndc_search', (string) $resolution->toArray()['next_step']);
        $this->assertStringContainsString('RxNav', (string) $resolution->toArray()['next_step']);
        $this->assertCount(1, array_filter($this->rxnavRequests, fn (string $url): bool => str_contains($url, '/ndcstatus.json')), 'an 11-digit NDC has one candidate');
        $this->assertCount(1, $this->rxnavRequests, 'nothing more is fetched for an NDC RxNorm does not know');
    }

    public function test_rxnav_unavailable_leaves_the_ndc_unresolved_and_is_not_cached(): void
    {
        $this->fakeOpenFda(rxnavDown: true);

        $resolution = $this->ndc->lookup('00093015010');

        $this->assertSame(NdcResolution::UNRESOLVED, $resolution->status);
        $this->assertSame(NdcResolution::REASON_UNAVAILABLE, $resolution->reason);
        $this->assertStringContainsString('could not be reached', (string) $resolution->toArray()['next_step']);

        $this->fakeOpenFda();
        $this->assertSame(NdcResolution::RESOLVED, $this->ndc->lookup('00093015010')->status, 'the outage was not remembered');
    }

    public function test_only_the_ndc_and_the_rxcui_are_sent_and_answers_are_cached(): void
    {
        $this->fakeOpenFda();

        $this->ndc->lookup('00093015010');
        $first = $this->rxnavRequests;
        $this->ndc->lookup('00093015010');

        $this->assertSame($first, $this->rxnavRequests, 'the repeat came from the cache');
        $this->assertCount(3, $first);
        $this->assertStringStartsWith('https://rxnav.nlm.nih.gov/REST/ndcstatus.json?ndc=00093015010', $first[0]);
        $this->assertStringContainsString('/ndcproperties.json?id=00093015010', $first[1]);
        $this->assertStringContainsString('/rxcui/993781/related.json?tty=SCDC+DF+IN', $first[2]);
    }

    public function test_the_base_url_comes_from_config(): void
    {
        config(['services.rxnav.base_url' => 'https://rxnav.example.test/REST']);
        $this->fakeOpenFda();

        $this->ndc->lookup('00093015010');

        $this->assertCount(3, $this->rxnavRequests);
        $this->assertStringStartsWith('https://rxnav.example.test/REST/ndcstatus.json?', $this->rxnavRequests[0]);
    }

    public function test_the_chosen_package_falls_back_to_rxnav_too(): void
    {
        $this->fakeOpenFda();

        $resolution = $this->ndc->lookupChosen('0093-0150-10', '0093015010');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('0093015010', $resolution->ndcAsCaptured);
        $this->assertSame('rxnav', $resolution->chosen()?->source);

        $this->fakeOpenFda(down: true);
        Cache::flush();
        $this->assertSame(NdcResolution::REASON_UNAVAILABLE, $this->ndc->lookupChosen('0093-0150-10', '0093015010')->reason);
        $this->assertSame([], $this->rxnavRequests);

        $this->fakeOpenFda();
        $this->assertSame(NdcResolution::REASON_NOT_FOUND, $this->ndc->lookupChosen('1234-5678-90', '1234567890')->reason);
    }

    public function test_ten_digits_without_hyphens_try_each_reading_in_rxnav(): void
    {
        $this->fakeOpenFda();

        // 0093015010 reads as 0093-0150-10, 00930-150-10 and 00930-1501-0; only the first is in RxNorm.
        $resolution = $this->ndc->lookup('0093015010');

        $this->assertSame(NdcResolution::RESOLVED, $resolution->status);
        $this->assertSame('0093-0150-10', $resolution->chosen()?->ndc10);
        $this->assertCount(3, array_filter($this->rxnavRequests, fn (string $url): bool => str_contains($url, '/ndcstatus.json')));
    }
}
