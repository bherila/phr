<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiProposal;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\EhiCapacityLifecycle;
use Tests\Support\EhiCapacityOracle;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\TestCase;

/** The small regression validates the calibration shape; large profiles run only in CI. */
final class EhiLabCapacityFixtureTest extends TestCase
{
    use EhiIntakeFixtures;

    public function test_the_lab_capacity_fixture_preserves_every_result_across_panel_boundaries(): void
    {
        $this->setUpEhi();
        Queue::fake();
        // Force the tiny archive through multiple real upload chunks, without raising a guard.
        config(['phr.native_restore_chunk_bytes' => 16384]);
        Storage::fake('phr_exports');
        Storage::fake('phr_dicom');
        try {
            $owner = User::factory()->create(['user_role' => 'user']);
            $patient = $this->ehiPatient($owner);
            PhrPatientUserAccess::create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => 'owner', 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
            $package = $this->ehiIntakeDirectory(EhiClinicalPackage::labCapacity(11)->build(), $patient, $owner->id)->package;
            $package = app(EhiBindingService::class)->bind($package, $patient, $owner->id, null, 'Synthetic Capacity Source');
            $preview = app(EhiProposalService::class)->preview($package, $patient, $owner->id);
            self::assertSame(11, $preview->counts[EhiLabResultMapping::NAME]['create']);
            self::assertNotEmpty($preview->counts['document']);
            self::assertNotEmpty($preview->counts['immunization']);
            self::assertSame(11, PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiLabResultMapping::NAME)->count());
            $lifecycle = (new EhiCapacityLifecycle)->run($patient, (int) $owner->id, 0);
            self::assertSame('completed', $lifecycle['status']);
            self::assertTrue($lifecycle['round_trip_verified']);
            self::assertTrue($lifecycle['cleanup_verified']);
            self::assertTrue($lifecycle['independent_download_verified']);
            self::assertTrue($lifecycle['access_semantics_verified']);
            self::assertGreaterThan(0, $lifecycle['record_stream_bytes']);
            self::assertSame(1, $lifecycle['review_state']['phr_lab_results']);
            self::assertSame(2, $lifecycle['review_state']['phr_ehi_proposal_decisions']);
        } finally {
            $this->tearDownEhi();
        }
    }

    public function test_independent_oracle_refuses_missing_changed_values_and_overwritten_prior_decisions(): void
    {
        $this->setUpEhi();
        Queue::fake();
        try {
            $owner = User::factory()->create(['user_role' => 'user']);
            $patient = $this->ehiPatient($owner);
            $package = $this->ehiIntakeDirectory(EhiClinicalPackage::labCapacity(3)->build(), $patient, $owner->id)->package;
            $package = app(EhiBindingService::class)->bind($package, $patient, $owner->id, null, 'Synthetic oracle source');
            app(EhiProposalService::class)->preview($package, $patient, $owner->id);
            (new EhiCapacityLifecycle)->reviewAll($patient, $owner->id);
            $oracle = new EhiCapacityOracle;
            $oracle->assertLabs($patient, 3, 0);
            $before = $oracle->snapshot($patient);
            try {
                $oracle->assertLabs($patient, 3, 0, 1, 1);
                self::fail('An ignored declared changed value must not qualify.');
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('independent declaration', $failure->getMessage());
            }
            $decision = DB::table('phr_ehi_proposal_decisions')->where('patient_id', $patient->id)->orderBy('id')->value('id');
            DB::table('phr_ehi_proposal_decisions')->where('id', $decision)->update(['mapping_version' => 'synthetic-overwritten']);
            try {
                $oracle->assertPriorPreserved($before, $oracle->snapshot($patient));
                self::fail('Overwritten decisions with unchanged counts must not qualify.');
            } catch (RuntimeException $failure) {
                self::assertStringContainsString('overwritten or removed', $failure->getMessage());
                self::assertStringNotContainsString('synthetic-overwritten', $failure->getMessage());
            }
        } finally {
            $this->tearDownEhi();
        }
    }
}
