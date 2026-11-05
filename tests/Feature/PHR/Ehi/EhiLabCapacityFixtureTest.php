<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiProposal;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiLabResultMapping;
use Illuminate\Support\Facades\Queue;
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
        try {
            $owner = User::factory()->create(['user_role' => 'user']);
            $patient = $this->ehiPatient($owner);
            $package = $this->ehiIntakeDirectory(EhiClinicalPackage::labCapacity(11)->build(), $patient, $owner->id)->package;
            $package = app(EhiBindingService::class)->bind($package, $patient, $owner->id, null, 'Synthetic Capacity Source');
            $preview = app(EhiProposalService::class)->preview($package, $patient, $owner->id);
            self::assertSame(11, $preview->counts[EhiLabResultMapping::NAME]['create']);
            self::assertNotEmpty($preview->counts['document']);
            self::assertNotEmpty($preview->counts['immunization']);
            self::assertSame(11, PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', EhiLabResultMapping::NAME)->count());
        } finally {
            $this->tearDownEhi();
        }
    }
}
