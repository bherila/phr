<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiFinding;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Models\PhrEhiRecordLink;
use App\Models\PhrEhiTableAccount;
use App\Models\PhrImmunization;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiPackageSummary;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\EhiReviewRefused;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyRefused;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\EhiFakeMapping;
use Tests\Support\EhiProjectionFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

class EhiProposalServiceTest extends TestCase
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

    public function test_preview_persists_digested_proposals_and_re_preview_converges(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $service = app(EhiProposalService::class);

        $preview = $service->preview($package, $patient, $owner->id);

        self::assertSame(['synthetic' => ['create' => 3, 'retain' => 1]], $preview->counts);
        self::assertSame(['pending' => 4], $preview->statuses);
        self::assertSame(['synthetic_deleted_row' => 1], $preview->findings);
        self::assertSame(1, $preview->projection->tablesProjected);
        $finding = PhrEhiFinding::query()->where('code', 'synthetic_deleted_row')->sole();
        self::assertSame('info', $finding->severity);
        self::assertSame(['stage' => 'preview', 'mapping' => 'synthetic'], $finding->detail_json);
        self::assertNotNull($finding->line_number);

        $proposal = $this->proposalFor($package, '100', '1');
        self::assertSame('ehi-syn-1', $proposal->mapping_version);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $proposal->kind);
        self::assertSame([EhiFakeMapping::revisionFor($package->id, '100', '1')?->id], $proposal->revision_ids_json);
        self::assertSame(64, strlen($proposal->preview_digest));
        self::assertSame(64, strlen($proposal->revision_digest));
        self::assertNull($proposal->target_digest);

        $before = PhrEhiProposal::query()->orderBy('id')->get(['id', 'preview_digest', 'updated_at'])->toArray();
        $again = $service->preview($package, $patient, $owner->id);
        self::assertSame($preview->digest, $again->digest);
        self::assertTrue($again->projection->replayed);
        self::assertSame($before, PhrEhiProposal::query()->orderBy('id')->get(['id', 'preview_digest', 'updated_at'])->toArray());
        self::assertSame(1, PhrEhiFinding::query()->where('code', 'synthetic_deleted_row')->count());
        self::assertSame(0, PhrImmunization::query()->count(), 'a preview writes no clinical rows');
    }

    public function test_pending_proposals_no_longer_produced_are_superseded_and_decided_ones_never_rewritten(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $decided = $this->proposalFor($package, '100', '1');
        $service->review($decided, PhrEhiProposal::DECISION_REJECT, $owner->id, $decided->preview_digest);
        $decidedRow = $decided->refresh()->getAttributes();
        $pending = $this->proposalFor($package, '100', '2');

        // The mapping now proposes different values and no longer covers one subject.
        EhiFakeMapping::$suffix = ' revised';
        $skipped = EhiMappingContext::keyDigest(['ITEM_ID' => '0100', 'LINE' => '1']);
        EhiFakeMapping::$skip = [$skipped];
        $preview = $service->preview($package, $patient, $owner->id);

        self::assertSame($decidedRow, $decided->refresh()->getAttributes(), 'a decided proposal is never rewritten');
        $pending->refresh();
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $pending->status);
        self::assertStringEndsWith(' revised', (string) ($pending->proposed_json['vaccine_name'] ?? ''));
        $superseded = PhrEhiProposal::query()->where('subject_key', $skipped)->sole();
        self::assertSame(PhrEhiProposal::STATUS_SUPERSEDED, $superseded->status);
        self::assertSame(['pending' => 2, 'rejected' => 1, 'superseded' => 1], $preview->statuses);
        $this->assertRefused(EhiReviewRefused::NOT_PENDING, fn () => $service->review($superseded, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $superseded->preview_digest));

        // Produced again, it reopens as pending.
        EhiFakeMapping::$skip = [];
        $service->preview($package, $patient, $owner->id);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $superseded->refresh()->status);
    }

    public function test_accept_creates_the_record_links_every_revision_and_appends_history(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $proposal = $this->proposalFor($package, '100', '1');

        $accepted = $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);

        self::assertSame(PhrEhiProposal::STATUS_ACCEPTED, $accepted->status);
        self::assertSame($owner->id, $accepted->decided_by_user_id);
        self::assertNotNull($accepted->decided_at);
        $record = PhrImmunization::query()->sole();
        self::assertSame(PhrReviewStatus::CONFIRMED, $record->review_status);
        $link = PhrEhiRecordLink::query()->sole();
        self::assertSame([$link->revision_id], $proposal->revision_ids_json);
        self::assertSame($record->id, $link->immunization_id);
        self::assertSame(PhrEhiRecordLink::KIND_PROJECTED, $link->link_kind);
        self::assertSame($proposal->id, $link->proposal_id);
        self::assertSame('ehi-syn-1', $link->mapping_version);
        $decision = PhrEhiProposalDecision::query()->sole();
        self::assertSame(PhrEhiProposal::DECISION_ACCEPT, $decision->decision);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $decision->previous_status);
        self::assertSame($proposal->revision_digest, $decision->revision_digest);
        self::assertSame($record->id, $decision->linked_immunization_id);
        self::assertSame(PhrEhiOccurrence::DISPOSITION_PROJECTED, PhrEhiOccurrence::query()->where('revision_id', $link->revision_id)->value('disposition'));
        $account = PhrEhiTableAccount::query()->where('package_id', $package->id)->where('table_name', EhiFakeMapping::TABLE)->sole();
        self::assertSame(1, $account->disposition_counts_json['projected'] ?? null);
        self::assertSame(0, EhiPackageSummary::forPackage($package)->unreconciledTables);

        // The same decision again is a no-op; any other is refused.
        $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
        self::assertSame(1, PhrImmunization::query()->count());
        self::assertSame(1, PhrEhiProposalDecision::query()->count());
        $this->assertRefused(EhiReviewRefused::NOT_PENDING, fn () => $service->review($proposal, PhrEhiProposal::DECISION_REJECT, $owner->id, $proposal->preview_digest));
    }

    public function test_reject_and_retain_write_no_clinical_rows(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $rejected = $this->proposalFor($package, '100', '1');
        $retained = $this->proposalFor($package, '300', '1');
        self::assertSame(PhrEhiProposal::KIND_RETAIN, $retained->kind);

        $service->review($rejected, PhrEhiProposal::DECISION_REJECT, $owner->id, $rejected->preview_digest);
        $this->assertRefused(EhiReviewRefused::DECISION_NOT_ALLOWED, fn () => $service->review($retained, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $retained->preview_digest));
        $service->review($retained, PhrEhiProposal::DECISION_RETAIN, $owner->id, $retained->preview_digest);

        self::assertSame(PhrEhiProposal::STATUS_REJECTED, $rejected->refresh()->status);
        self::assertSame(PhrEhiProposal::STATUS_RETAINED, $retained->refresh()->status);
        self::assertSame(0, PhrImmunization::query()->count());
        self::assertSame(0, PhrEhiRecordLink::query()->count());
        self::assertSame(2, PhrEhiProposalDecision::query()->whereNull('linked_immunization_id')->count());
        self::assertSame(2, PhrEhiOccurrence::query()->where('disposition', PhrEhiOccurrence::DISPOSITION_RETAINED)->count());
    }

    public function test_link_existing_bridges_to_a_prior_record(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $existing = $this->immunization($patient, 'Synthetic vaccine A '.EhiSyntheticPackage::MARKER);
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $proposal = $this->proposalFor($package, '100', '1');
        self::assertSame(PhrEhiProposal::KIND_POSSIBLE_DUPLICATE, $proposal->kind);
        self::assertSame([$existing->id], $proposal->candidate_ids_json);

        $this->assertRefused(EhiReviewRefused::LINK_TARGET_REQUIRED, fn () => $service->review($proposal, PhrEhiProposal::DECISION_LINK, $owner->id, $proposal->preview_digest));
        $deleted = $this->immunization($patient, 'Synthetic deleted');
        $deleted->delete();
        $this->assertRefused(EhiReviewRefused::TARGET_WITHDRAWN, fn () => $service->review($proposal, PhrEhiProposal::DECISION_LINK, $owner->id, $proposal->preview_digest, $deleted->id));

        $linked = $service->review($proposal, PhrEhiProposal::DECISION_LINK, $owner->id, $proposal->preview_digest, $existing->id);

        self::assertSame(PhrEhiProposal::STATUS_LINKED, $linked->status);
        self::assertSame(2, PhrImmunization::query()->withTrashed()->count(), 'linking creates no record');
        $link = PhrEhiRecordLink::query()->sole();
        self::assertSame(PhrEhiRecordLink::KIND_BRIDGED, $link->link_kind);
        self::assertSame($existing->id, $link->immunization_id);
        self::assertSame($existing->id, PhrEhiProposalDecision::query()->sole()->linked_immunization_id);
        self::assertSame(PhrEhiOccurrence::DISPOSITION_CORROBORATING, PhrEhiOccurrence::query()->where('revision_id', $link->revision_id)->value('disposition'));
        self::assertTrue($deleted->refresh()->trashed());
        // Idempotent for the same link only.
        $service->review($proposal, PhrEhiProposal::DECISION_LINK, $owner->id, $proposal->preview_digest, $existing->id);
        $this->assertRefused(EhiReviewRefused::NOT_PENDING, fn () => $service->review($proposal, PhrEhiProposal::DECISION_LINK, $owner->id, $proposal->preview_digest, $deleted->id));
    }

    public function test_changed_and_unchanged_later_evidence_update_or_corroborate_after_review(): void
    {
        [$owner, $patient, $first] = $this->previewable('first');
        $service = app(EhiProposalService::class);
        $service->preview($first, $patient, $owner->id);
        foreach ([['100', '1'], ['100', '2']] as [$item, $line]) {
            $proposal = $this->proposalFor($first, $item, $line);
            $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
        }
        $updated = PhrImmunization::query()->orderBy('id')->firstOrFail();

        $rows = $this->standardItems();
        $rows[0][2] = 'Synthetic vaccine A corrected';
        $second = $this->boundPackage($this->itemPackage($rows), $patient, $owner, 'second');
        $preview = $service->preview($second, $patient, $owner->id);
        self::assertSame(['synthetic' => ['corroborate' => 1, 'create' => 1, 'retain' => 1, 'update' => 1]], $preview->counts);
        // Unchanged, already-linked content is corroborating evidence from the start.
        $corroborate = $this->proposalFor($second, '100', '2');
        self::assertSame(PhrEhiOccurrence::DISPOSITION_CORROBORATING, PhrEhiOccurrence::query()->where('package_id', $second->id)->whereIn('revision_id', $corroborate->revision_ids_json)->value('disposition'));

        $update = $this->proposalFor($second, '100', '1');
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        self::assertSame($updated->id, $update->target_id);
        self::assertNotNull($update->target_digest);
        $this->assertRefused(EhiReviewRefused::DECISION_NOT_ALLOWED, fn () => $service->review($update, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $update->preview_digest));
        $service->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest);
        self::assertSame('Synthetic vaccine A corrected', $updated->refresh()->vaccine_name);
        self::assertSame(2, PhrEhiRecordLink::query()->where('immunization_id', $updated->id)->where('link_kind', PhrEhiRecordLink::KIND_PROJECTED)->count());

        $service->review($corroborate, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $corroborate->preview_digest);
        self::assertSame(2, PhrImmunization::query()->count());
        self::assertSame(1, PhrEhiRecordLink::query()->where('link_kind', PhrEhiRecordLink::KIND_CORROBORATING)->count());
    }

    public function test_stale_preview_target_change_and_withdrawn_targets_are_refused_without_writes(): void
    {
        [$owner, $patient, $first] = $this->previewable('first');
        $service = app(EhiProposalService::class);
        $service->preview($first, $patient, $owner->id);
        $created = $this->proposalFor($first, '100', '1');
        $this->assertRefused(EhiReviewRefused::STALE_PREVIEW, fn () => $service->review($created, PhrEhiProposal::DECISION_ACCEPT, $owner->id, str_repeat('0', 64)));
        $service->review($created, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $created->preview_digest);
        $record = PhrImmunization::query()->sole();

        $rows = $this->standardItems();
        $rows[0][2] = 'Synthetic vaccine A corrected';
        $second = $this->boundPackage($this->itemPackage($rows), $patient, $owner, 'second');
        $service->preview($second, $patient, $owner->id);
        $update = $this->proposalFor($second, '100', '1');

        // Changed since the preview: refused until re-previewed.
        $record->forceFill(['notes' => 'Synthetic manual note'])->save();
        $this->assertRefused(EhiReviewRefused::STALE_TARGET, fn () => $service->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest));
        $service->preview($second, $patient, $owner->id);
        $update->refresh();

        // Deleted or retracted since: refused and never resurrected.
        $record->delete();
        $this->assertRefused(EhiReviewRefused::TARGET_WITHDRAWN, fn () => $service->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest));
        self::assertTrue($record->refresh()->trashed());
        $record->restore();
        $record->forceFill(['retracted_at' => now()])->save();
        $service->preview($second, $patient, $owner->id);
        $update->refresh();
        $this->assertRefused(EhiReviewRefused::TARGET_WITHDRAWN, fn () => $service->review($update, PhrEhiProposal::DECISION_APPROVE_UPDATE, $owner->id, $update->preview_digest));

        self::assertSame('Synthetic vaccine A '.EhiSyntheticPackage::MARKER, $record->refresh()->vaccine_name);
        self::assertNotNull($record->retracted_at);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $update->refresh()->status);
        self::assertSame(1, PhrEhiProposalDecision::query()->count());
    }

    public function test_a_second_concurrent_decision_and_a_revoked_reviewer_are_refused(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $first = $this->proposalFor($package, '100', '1');
        $stale = PhrEhiProposal::query()->findOrFail($first->id);

        $service->review($first, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $first->preview_digest);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $stale->status, 'the second reviewer still holds the pending copy');
        $this->assertRefused(EhiReviewRefused::NOT_PENDING, fn () => $service->review($stale, PhrEhiProposal::DECISION_REJECT, $owner->id, $stale->preview_digest));
        self::assertSame(1, PhrImmunization::query()->count());

        $viewer = User::factory()->create(['user_role' => 'user']);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $viewer->id, 'access_level' => PhrPatientUserAccess::LEVEL_VIEWER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);
        $other = $this->proposalFor($package, '100', '2');
        $this->expectException(AuthorizationException::class);
        $service->review($other, PhrEhiProposal::DECISION_ACCEPT, $viewer->id, $other->preview_digest);
    }

    public function test_a_mapping_refusal_or_version_change_rolls_back_and_keeps_its_reason(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $proposal = $this->proposalFor($package, '100', '1');

        EhiFakeMapping::$afterApply = static function (): void {
            throw new EhiApplyRefused('identity_exists', 'synthetic refusal');
        };
        $this->assertRefused('identity_exists', fn () => $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest));
        self::assertSame(0, PhrImmunization::query()->withTrashed()->count(), 'the mapping write rolled back');
        self::assertSame(0, PhrEhiRecordLink::query()->count());
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $proposal->refresh()->status);
        EhiFakeMapping::$afterApply = null;

        EhiFakeMapping::$version = 'ehi-syn-2';
        $this->assertRefused(EhiReviewRefused::STALE_MAPPING, fn () => $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest));
        self::assertSame(0, PhrEhiProposalDecision::query()->count());
    }

    public function test_patient_deletion_removes_every_evidence_and_review_row(): void
    {
        [$owner, $patient, $package] = $this->previewable();
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $proposal = $this->proposalFor($package, '100', '1');
        $service->review($proposal, PhrEhiProposal::DECISION_ACCEPT, $owner->id, $proposal->preview_digest);
        $tables = ['phr_ehi_entities', 'phr_ehi_revisions', 'phr_ehi_occurrences', 'phr_ehi_proposals', 'phr_ehi_proposal_decisions', 'phr_ehi_record_links'];
        foreach ($tables as $table) {
            self::assertGreaterThan(0, DB::table($table)->where('patient_id', $patient->id)->count(), $table);
        }

        $preview = $this->actingAs($owner)->getJson("/api/phr/data-hub/patients/{$patient->id}/deletion-preview")
            ->assertOk()->assertJsonCount(0, 'deletion_preview.blockers');
        foreach ($tables as $table) {
            $preview->assertJsonPath("deletion_preview.record_counts.{$table}", DB::table($table)->where('patient_id', $patient->id)->count());
        }
        $this->actingAs($owner)->deleteJson("/api/phr/patients/{$patient->id}", [
            'confirmation' => 'DELETE',
            'preview_digest' => (string) $preview->json('deletion_preview.preview_digest'),
        ])->assertAccepted();

        foreach ([...$tables, 'phr_immunizations', 'phr_ehi_packages'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }
    }

    /** @return array{User, PhrPatient, PhrEhiPackage} */
    private function previewable(string $name = 'package'): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient, $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner, $name)];
    }

    private function proposalFor(PhrEhiPackage $package, string $item, string $line): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)
            ->where('subject_key', EhiMappingContext::keyDigest(['ITEM_ID' => $item, 'LINE' => $line]))->sole();
    }

    private function immunization(PhrPatient $patient, string $name): PhrImmunization
    {
        return PhrImmunization::query()->create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'review_status' => PhrReviewStatus::CONFIRMED,
            'vaccine_name' => $name,
        ]);
    }

    private function assertRefused(string $reason, \Closure $call): void
    {
        try {
            $call();
            self::fail("Expected EhiReviewRefused ({$reason}).");
        } catch (EhiReviewRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertStringNotContainsString(EhiSyntheticPackage::MARKER, $refused->getMessage());
        }
    }
}
