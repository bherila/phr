<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedication;
use App\Models\PhrMedicationFill;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

final class PhrFillsResolveDiscontinuedCommandTest extends TestCase
{
    use FakesOpenFda;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->fakeOpenFda();
    }

    /** @param array<string, mixed> $overrides */
    private function unresolved(string $ndc = '00093015010', array $overrides = []): PhrMedicationFill
    {
        return PhrMedicationFill::factory()->create([
            'ndc_status' => PhrMedicationFill::NDC_UNRESOLVED,
            'ndc_source' => null,
            'ndc_as_captured' => $ndc,
            'ndc_10' => '0093-0150-10',
            'ndc_11' => '00093015010',
            'substance_names' => null, 'strengths' => null, 'brand_name' => null, 'dosage_form' => null, 'route' => null,
            'labeler_name' => null, 'package_description' => null, 'rxcui' => null, 'fda_snapshot' => null, 'fda_fetched_at' => null,
            'drug_as_printed' => 'APAP W/ CODEINE 300-30',
            'review_status' => PhrReviewStatus::PENDING,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(PhrMedicationFill $fill): array
    {
        return (array) DB::table('phr_medication_fills')->where('id', $fill->id)->first();
    }

    public function test_a_dry_run_looks_up_and_reports_but_writes_nothing(): void
    {
        $fill = $this->unresolved();
        $before = $this->row($fill);

        $this->artisan('phr:fills:resolve-discontinued', ['--dry-run' => true, '--delay-ms' => 0])
            ->expectsOutputToContain('[dry run] Looked up 1 unresolved fill(s); would resolve 1 (1 discontinued)')
            ->assertSuccessful();

        $this->assertEquals($before, $this->row($fill));
        $this->assertNotSame([], $this->rxnavRequests);
    }

    public function test_it_resolves_the_drug_and_status_only_and_a_rerun_changes_nothing(): void
    {
        $fill = $this->unresolved();
        $before = $this->row($fill);

        $this->artisan('phr:fills:resolve-discontinued', ['--delay-ms' => 0])
            ->expectsOutputToContain('resolved 1 (1 discontinued)')
            ->assertSuccessful();

        $after = $this->row($fill);
        $changed = array_keys(array_diff_assoc($after, $before));
        $drugAndStatus = ['brand_name', 'dosage_form', 'fda_fetched_at', 'fda_snapshot', 'labeler_name', 'medication_id', 'ndc_source', 'ndc_status', 'package_description', 'route', 'rxcui', 'strengths', 'substance_names', 'updated_at'];
        $this->assertSame([], array_values(array_diff($changed, $drugAndStatus)), 'only drug columns, the status and the medication link change');
        $this->assertContains('ndc_status', $changed);
        foreach (['ndc_as_captured', 'ndc_10', 'ndc_11', 'drug_as_printed', 'review_status', 'quantity', 'filled_on', 'directions', 'rx_number', 'pharmacy_name'] as $captured) {
            $this->assertSame($before[$captured], $after[$captured], "{$captured} is a captured value");
        }

        $fill->refresh();
        $this->assertSame('resolved', $fill->ndc_status);
        $this->assertSame('rxnav', $fill->ndc_source);
        $this->assertTrue($fill->isDiscontinued());
        $this->assertSame(['acetaminophen', 'codeine phosphate'], $fill->substance_names);
        $this->assertSame(['300 mg', '30 mg'], $fill->strengths);
        $this->assertSame('tablet', $fill->dosage_form);
        $this->assertSame('oral', $fill->route);
        $this->assertSame('Teva Pharmaceuticals USA, Inc.', $fill->labeler_name);
        $this->assertSame('993781', $fill->rxcui);
        $this->assertSame('rxnav', $fill->fda_snapshot['source']);
        $this->assertSame('acetaminophen; codeine phosphate', PhrMedication::query()->findOrFail($fill->medication_id)->name, 'the medication is linked like after a person\'s edit');

        $requests = count($this->rxnavRequests);
        $this->artisan('phr:fills:resolve-discontinued', ['--delay-ms' => 0])->expectsOutputToContain('Looked up 0 unresolved fill(s)')->assertSuccessful();
        $this->assertEquals($after, $this->row($fill));
        $this->assertSame($requests, count($this->rxnavRequests), 'a resolved fill is not looked up again');
    }

    public function test_fills_a_person_or_the_resolver_owns_are_left_alone(): void
    {
        $manual = $this->unresolved('00093015010', ['ndc_status' => PhrMedicationFill::NDC_MANUAL, 'drug_as_printed' => 'typed by hand']);
        $ambiguous = $this->unresolved('00093015010', ['ndc_status' => PhrMedicationFill::NDC_AMBIGUOUS]);
        $resolved = $this->unresolved('00093015010', ['ndc_status' => PhrMedicationFill::NDC_RESOLVED, 'ndc_source' => 'openfda', 'substance_names' => ['typed by a person']]);
        $rejected = $this->unresolved('00093015010', ['review_status' => PhrReviewStatus::REJECTED]);
        $retracted = $this->unresolved('00093015010', ['retracted_at' => now()]);
        $deleted = $this->unresolved('00093015010');
        $deleted->delete();
        $noNdc = $this->unresolved('not an ndc');
        $unknown = $this->unresolved('12345-6789-0', ['ndc_10' => '12345-6789-0']);
        $untouched = array_map(fn (PhrMedicationFill $fill): array => $this->row($fill), [$manual, $ambiguous, $resolved, $rejected, $retracted, $deleted, $noNdc, $unknown]);

        $this->artisan('phr:fills:resolve-discontinued', ['--delay-ms' => 0])
            ->expectsOutputToContain('Looked up 1 unresolved fill(s); resolved 0 (0 discontinued); 1 still unresolved')
            ->assertSuccessful();

        $this->assertEquals($untouched, array_map(fn (PhrMedicationFill $fill): array => $this->row($fill), [$manual, $ambiguous, $resolved, $rejected, $retracted, $deleted, $noNdc, $unknown]));
        $this->assertSame(0, PhrMedication::query()->count());
    }

    public function test_a_fill_edited_while_the_command_runs_is_left_alone(): void
    {
        $fill = $this->unresolved();
        $this->onRxnavRequest = function () use ($fill): void {
            // A person fixes the printed drug name between the read and the write.
            DB::table('phr_medication_fills')->where('id', $fill->id)->update(['drug_as_printed' => 'CODEINE-APAP #3']);
        };

        $this->artisan('phr:fills:resolve-discontinued', ['--delay-ms' => 0])
            ->expectsOutputToContain('1 changed while running')
            ->assertSuccessful();

        $fill->refresh();
        $this->assertSame('unresolved', $fill->ndc_status);
        $this->assertSame('CODEINE-APAP #3', $fill->drug_as_printed);
        $this->assertNull($fill->ndc_source);
    }

    public function test_an_outage_leaves_fills_unresolved_and_says_to_run_again(): void
    {
        $fill = $this->unresolved();
        $before = $this->row($fill);
        $this->fakeOpenFda(rxnavDown: true);

        $this->artisan('phr:fills:resolve-discontinued', ['--delay-ms' => 0])
            ->expectsOutputToContain('1 could not be looked up')
            ->expectsOutputToContain('run the command again later')
            ->assertSuccessful();

        $this->assertEquals($before, $this->row($fill));
    }

    public function test_it_gives_up_after_repeated_outages(): void
    {
        foreach (range(1, 8) as $i) {
            $this->unresolved(sprintf('0093-0150-%02d', $i));
        }
        $this->fakeOpenFda(rxnavDown: true);

        $this->artisan('phr:fills:resolve-discontinued', ['--delay-ms' => 0])
            ->expectsOutputToContain('5 could not be looked up')
            ->expectsOutputToContain('Stopped early')
            ->assertSuccessful();
    }

    public function test_it_waits_between_fills_and_stops_at_the_time_limit(): void
    {
        $this->unresolved('0093-0150-11');
        $this->unresolved('0093-0150-12');

        $this->artisan('phr:fills:resolve-discontinued', ['--delay-ms' => 1100, '--max-seconds' => 1])
            ->expectsOutputToContain('Looked up 1 unresolved fill(s)')
            ->expectsOutputToContain('Stopped after 1 seconds')
            ->assertSuccessful();
    }
}
