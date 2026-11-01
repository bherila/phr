<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use App\Services\PHR\Ndc\NdcDrugDetail;
use App\Services\PHR\Ndc\NdcLabelService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The FDA label read on demand; the live API is never called. */
final class NdcLabelServiceTest extends TestCase
{
    private const string SET_ID = '52561b3e-c20f-3536-e063-6394a90a6198';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.openfda.key' => 'synthetic-key']);
    }

    public function test_a_label_is_found_by_package_ndc_returned_as_published_and_cached(): void
    {
        Http::fake(['api.fda.gov/drug/label.json*' => Http::response(['results' => [[
            'set_id' => self::SET_ID,
            'effective_time' => '20260521',
            'version' => '3',
            'indications_and_usage' => ['INDICATIONS & USAGE 1.1 Something'],
            'warnings_and_cautions' => ['5.1 Liver', '5.2 Muscle'],
            'dosage_and_administration' => [''],
            'spl_product_data_elements' => ['ignored'],
        ]]])]);

        $service = app(NdcLabelService::class);
        $label = $service->label('67046-1677-3', null);
        $again = $service->label('67046-1677-3', null);

        $this->assertSame('found', $label['status']);
        $this->assertSame('2026-05-21', $label['effective_date']);
        $this->assertSame(['indications_and_usage', 'warnings_and_cautions'], array_column($label['sections'], 'key'));
        $this->assertSame("5.1 Liver\n\n5.2 Muscle", $label['sections'][1]['text']);
        $this->assertSame('https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid='.self::SET_ID, $label['links']['dailymed']);
        $this->assertSame($label, $again);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'openfda.package_ndc:"67046-1677-3"'));
    }

    public function test_no_label_unreachable_and_invalid_input_are_distinct(): void
    {
        $service = app(NdcLabelService::class);

        Http::fake(['api.fda.gov/*' => Http::response(['error' => ['code' => 'NOT_FOUND']], 404)]);
        $this->assertSame('not_found', $service->label('67046-1677-3', null)['status']);
        $this->assertSame('invalid', $service->label(null, 'not-a-set-id')['status']);
        $this->assertSame('invalid', $service->label('nonsense', null)['status']);
    }

    public function test_an_unreachable_openfda_is_unavailable_not_missing(): void
    {
        Http::fake(['api.fda.gov/*' => Http::response('', 500)]);

        $this->assertSame('unavailable', app(NdcLabelService::class)->label('67046-1677-3', null)['status']);
    }

    public function test_the_fill_drug_detail_reads_the_stored_snapshot(): void
    {
        $fill = new PhrMedicationFill([
            'ndc_10' => '67046-1677-3', 'ndc_source' => 'openfda', 'ndc_status' => 'resolved', 'labeler_name' => 'Coupler Llc',
            'fda_snapshot' => [
                'product_ndc' => '67046-1677',
                'active_ingredients' => [['name' => 'ATORVASTATIN CALCIUM TRIHYDRATE', 'strength' => '10 mg/1']],
                'application_number' => 'ANDA217118',
                'pharm_class' => ['HMG-CoA Reductase Inhibitor [EPC]'],
                'openfda' => ['spl_set_id' => [self::SET_ID]],
            ],
        ]);

        $detail = NdcDrugDetail::forFill($fill);

        $this->assertSame('ANDA217118', $detail['application_number']);
        $this->assertSame(['HMG-CoA Reductase Inhibitor [EPC]'], $detail['pharm_class']);
        $this->assertSame('atorvastatin calcium trihydrate', $detail['ingredients'][0]['name']);
        $this->assertSame(self::SET_ID, $detail['spl_set_id']);
        $this->assertStringContainsString('67046-1677', (string) $detail['links']['ndc_directory']);
        $this->assertFalse($detail['discontinued']);
    }
}
