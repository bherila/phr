<?php

namespace Tests\Feature\PHR;

use App\Models\PhrLabResult;
use Tests\Support\PhrSyntheticChartFixture;
use Tests\TestCase;

class PhrSyntheticChartFixtureTest extends TestCase
{
    public function test_it_seeds_a_chart_the_api_serves_as_two_panels_and_conditions(): void
    {
        $owner = $this->createUser();
        $patient = PhrSyntheticChartFixture::seed($owner->id);

        $anchor = PhrLabResult::query()
            ->where('patient_id', $patient->id)
            ->where('test_name', PhrSyntheticChartFixture::RESPIRATORY_PANEL)
            ->firstOrFail();
        $panel = $this->actingAs($owner)
            ->getJson("/api/phr/patients/{$patient->id}/labs/{$anchor->id}")
            ->assertOk()
            ->json('panel');

        $this->assertSame(PhrSyntheticChartFixture::RESPIRATORY_PANEL, $panel['panel_name']);
        $this->assertCount(20, $panel['rows']);
        $values = array_column($panel['rows'], 'value');
        $this->assertContains('Not Detected', $values);
        $this->assertContains('Nonreactive', $values);
        $flags = array_column($panel['rows'], 'abnormal_flag');
        $this->assertContains('H', $flags);
        $this->assertContains('L', $flags);

        $this->getJson("/api/phr/patients/{$patient->id}/lab-results")->assertOk()->assertJsonCount(25, 'lab_results');
        $this->assertSame(2, PhrLabResult::query()->where('patient_id', $patient->id)->where('review_status', 'pending_review')->count());

        $this->getJson("/api/phr/patients/{$patient->id}/conditions")
            ->assertOk()
            ->assertJsonCount(5, 'conditions')
            ->assertJsonFragment(['icd10_code' => 'J30.1', 'review_status' => 'pending_review']);
    }
}
