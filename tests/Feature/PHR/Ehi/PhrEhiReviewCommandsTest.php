<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrImmunization;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\EhiFakeMapping;
use Tests\Support\EhiProjectionFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

class PhrEhiReviewCommandsTest extends TestCase
{
    use EhiProjectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhiProjection();
    }

    protected function tearDown(): void
    {
        $this->tearDownEhiProjection();
        parent::tearDown();
    }

    public function test_preview_proposals_and_review_print_no_source_values(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner);
        $base = ['--patient' => $patient->id, '--actor' => $owner->id];

        [$exit, $preview] = $this->runCommand('phr:ehi:preview', [...$base, '--package' => $package->id]);
        self::assertSame(0, $exit, $preview);
        foreach ([
            "package: {$package->id}",
            'clinical: projected',
            'projection: tables projected 1, key mismatch 0, absent 0',
            'rows: projected 0, corroborating 0, pending_review 4, retained 0, quarantined 0',
            'proposals synthetic: create 3, retain 1',
            'statuses: pending 4',
            'mapping findings: synthetic_deleted_row 1',
            'preview digest: ',
        ] as $expected) {
            self::assertStringContainsString($expected, $preview);
        }
        $this->assertSafe($preview);

        [$exit, $list] = $this->runCommand('phr:ehi:proposals', $base);
        self::assertSame(0, $exit, $list);
        $proposal = PhrEhiProposal::query()->where('subject_key', EhiMappingContext::keyDigest(['ITEM_ID' => '100', 'LINE' => '1']))->sole();
        self::assertStringContainsString("proposal {$proposal->id}: package {$package->id}, mapping synthetic, kind create, status pending, target (none), preview digest {$proposal->preview_digest}", $list);
        self::assertSame(4, substr_count($list, 'proposal '));
        $this->assertSafe($list);
        [, $retained] = $this->runCommand('phr:ehi:proposals', [...$base, '--status' => 'retained']);
        self::assertStringContainsString('No EHI proposals.', $retained);

        [$exit, $show] = $this->runCommand('phr:ehi:proposals', [...$base, '--show' => $proposal->id]);
        self::assertSame(0, $exit, $show);
        self::assertStringContainsString('vaccine_name: proposed', $show);
        self::assertStringContainsString('administered_on: proposed', $show);
        self::assertStringNotContainsString('health data', $show);
        $this->assertSafe($show);

        [$exit, $values] = $this->runCommand('phr:ehi:proposals', [...$base, '--show' => $proposal->id, '--values' => true]);
        self::assertSame(0, $exit, $values);
        self::assertStringContainsString('WARNING: the values below are health data', $values);
        self::assertStringContainsString(EhiSyntheticPackage::MARKER, $values, 'values appear only behind --values');
        self::assertSame(2, $this->runCommand('phr:ehi:proposals', [...$base, '--values' => true])[0]);

        [$exit, $stale] = $this->runCommand('phr:ehi:review', [...$base, '--proposal' => $proposal->id, '--decision' => 'accept', '--preview-digest' => str_repeat('a', 64)]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('refused: stale_preview', $stale);
        self::assertSame(0, PhrImmunization::query()->count());

        [$exit, $review] = $this->runCommand('phr:ehi:review', [...$base, '--proposal' => $proposal->id, '--decision' => 'accept', '--preview-digest' => $proposal->preview_digest]);
        self::assertSame(0, $exit, $review);
        $record = PhrImmunization::query()->sole();
        self::assertStringContainsString("proposal: {$proposal->id}", $review);
        self::assertStringContainsString('status: accepted', $review);
        self::assertStringContainsString("record: phr_immunizations {$record->id}", $review);
        $this->assertSafe($review);

        [$exit, $again] = $this->runCommand('phr:ehi:review', [...$base, '--proposal' => $proposal->id, '--decision' => 'reject', '--preview-digest' => $proposal->preview_digest]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('refused: not_pending', $again);
    }

    public function test_status_shows_projection_and_proposal_counts_and_which_stage_holds_the_lease(): void
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner);
        $base = ['--patient' => $patient->id, '--actor' => $owner->id, '--package' => $package->id];

        [$exit, $before] = $this->runCommand('phr:ehi:status', $base);
        self::assertSame(0, $exit, $before);
        self::assertStringContainsString('projection: (not started)', $before);
        self::assertStringContainsString('proposals: (none)', $before);

        app(EhiProposalService::class)->preview($package, $patient, $owner->id);
        $proposal = PhrEhiProposal::query()->where('subject_key', EhiMappingContext::keyDigest(['ITEM_ID' => '100', 'LINE' => '1']))->sole();
        app(EhiProposalService::class)->review($proposal, PhrEhiProposal::DECISION_REJECT, $owner->id, $proposal->preview_digest);
        [$exit, $after] = $this->runCommand('phr:ehi:status', $base);
        self::assertSame(0, $exit, $after);
        self::assertStringContainsString('projection: tables projected 1, key mismatch 0, absent 0', $after);
        self::assertStringContainsString('proposals: pending 3, rejected 1', $after);
        self::assertStringContainsString('pending: synthetic create 2, synthetic retain 1', $after);
        self::assertStringNotContainsString('in progress', $after);
        $this->assertSafe($after);

        // Intake and projection share the lease; clinical_status says which holds it.
        $package->forceFill(['clinical_status' => PhrEhiPackage::CLINICAL_PROJECTING, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(5)])->save();
        self::assertStringContainsString('projection in progress: yes', $this->runCommand('phr:ehi:status', $base)[1]);
        $package->forceFill(['lease_expires_at' => now()->subMinute()])->save();
        $interrupted = $this->runCommand('phr:ehi:status', $base)[1];
        self::assertStringContainsString('projection interrupted: yes', $interrupted);
        self::assertStringNotContainsString('in progress', $interrupted);
        $package->forceFill(['clinical_status' => PhrEhiPackage::CLINICAL_NOT_STARTED, 'lease_expires_at' => now()->addMinutes(5)])->save();
        $intake = $this->runCommand('phr:ehi:status', $base)[1];
        self::assertStringContainsString('intake in progress: yes', $intake);
        self::assertStringNotContainsString('projection in progress', $intake);
    }

    public function test_writable_commands_require_write_access(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $package = $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner);

        // Read access lists proposals; it cannot preview or review.
        self::assertSame(0, $this->runCommand('phr:ehi:proposals', ['--patient' => $patient->id, '--actor' => $viewer->id])[0]);
        $this->expectException(AuthorizationException::class);
        $this->runCommand('phr:ehi:preview', ['--patient' => $patient->id, '--actor' => $viewer->id, '--package' => $package->id]);
    }

    /** @return array{int, string} */
    private function runCommand(string $command, array $parameters): array
    {
        $exit = Artisan::call($command, $parameters);

        return [$exit, Artisan::output()];
    }

    private function assertSafe(string $output): void
    {
        self::assertStringNotContainsString(EhiSyntheticPackage::MARKER, $output);
        self::assertStringNotContainsString(EhiSyntheticPackage::PATIENT_KEY, $output);
        foreach ([EhiFakeMapping::TABLE, 'ITEM_ID', 'Synthetic vaccine', '2020-01-01', '.tsv', $this->ehiRoot] as $value) {
            self::assertStringNotContainsString($value, $output);
        }
    }
}
