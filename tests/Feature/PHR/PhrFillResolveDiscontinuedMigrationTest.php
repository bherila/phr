<?php

namespace Tests\Feature\PHR;

use App\Models\PhrMedicationFill;
use App\Services\PHR\Ndc\RxNavClient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\FakesOpenFda;
use Tests\TestCase;

/** The one-time deploy run that resolves fills saved unresolved before RxNav was a fallback. */
final class PhrFillResolveDiscontinuedMigrationTest extends TestCase
{
    use FakesOpenFda;
    use RefreshDatabase;

    private const string MIGRATION = 'migrations/2026_11_01_000006_run_fill_resolve_discontinued.php';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function unresolvedFill(): PhrMedicationFill
    {
        return PhrMedicationFill::factory()->create([
            'ndc_status' => PhrMedicationFill::NDC_UNRESOLVED, 'ndc_source' => null, 'ndc_as_captured' => '00093015010',
            'ndc_10' => '0093-0150-10', 'ndc_11' => '00093015010', 'substance_names' => null, 'strengths' => null, 'brand_name' => null,
            'dosage_form' => null, 'route' => null, 'labeler_name' => null, 'package_description' => null, 'rxcui' => null,
            'fda_snapshot' => null, 'fda_fetched_at' => null, 'drug_as_printed' => 'APAP W/ CODEINE',
        ]);
    }

    public function test_it_resolves_unresolved_fills_once_and_again_changes_nothing(): void
    {
        $this->fakeOpenFda();
        $fill = $this->unresolvedFill();

        $this->migration()->up();
        $resolved = DB::table('phr_medication_fills')->where('id', $fill->id)->first();
        $this->assertSame('resolved', $resolved->ndc_status);
        $this->assertSame('rxnav', $resolved->ndc_source);
        $this->assertSame('00093015010', $resolved->ndc_as_captured);

        $requests = count($this->rxnavRequests);
        $this->migration()->up();
        $this->assertEquals($resolved, DB::table('phr_medication_fills')->where('id', $fill->id)->first());
        $this->assertSame($requests, count($this->rxnavRequests), 'nothing is left to look up');
        $this->migration()->down();
    }

    public function test_an_rxnav_outage_never_fails_the_deploy_and_the_fill_stays_for_a_later_run(): void
    {
        $this->fakeOpenFda(rxnavDown: true);
        $fill = $this->unresolvedFill();
        $before = DB::table('phr_medication_fills')->where('id', $fill->id)->first();

        $this->migration()->up();

        $this->assertEquals($before, DB::table('phr_medication_fills')->where('id', $fill->id)->first());

        $this->fakeOpenFda();
        $this->migration()->up();
        $this->assertSame('resolved', $fill->refresh()->ndc_status);
    }

    public function test_any_exception_is_logged_as_a_warning_and_swallowed(): void
    {
        $this->fakeOpenFda();
        $fill = $this->unresolvedFill();
        $this->app->bind(RxNavClient::class, fn (): RxNavClient => new class extends RxNavClient
        {
            public function findNdc(string $ndc11): ?array
            {
                throw new RuntimeException('synthetic failure');
            }
        });
        Log::spy();

        $this->migration()->up();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'skipped') && $context['error'] === 'synthetic failure');
        $this->assertSame('unresolved', $fill->refresh()->ndc_status);
    }

    public function test_an_empty_database_or_one_with_nothing_unresolved_makes_no_external_call(): void
    {
        Http::fake();
        $this->migration()->up();

        PhrMedicationFill::factory()->create();
        $this->migration()->up();

        Http::assertNothingSent();
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
