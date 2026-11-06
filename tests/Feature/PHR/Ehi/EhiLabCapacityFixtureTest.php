<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiProposal;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EhiCapacityLifecycle;
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
}
