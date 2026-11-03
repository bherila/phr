<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrDocument;
use App\Models\PhrEhiFinding;
use App\Models\PhrEhiOccurrence;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPackageMember;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiProposalDecision;
use App\Models\PhrEhiSource;
use App\Models\PhrImmunization;
use App\Models\PhrLabResult;
use App\Models\PhrOfficeVisit;
use App\Models\PhrPatient;
use App\Models\PhrPatientUserAccess;
use App\Models\User;
use App\Services\PHR\Import\Ehi\EhiBindingService;
use App\Services\PHR\Import\Ehi\EhiFindingCode;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EhiClinicalPackage;
use Tests\Support\EhiIntakeFixtures;
use Tests\Support\EhiSyntheticPackage;
use Tests\TestCase;

/**
 * What a person rejected stays decided while the source says the same thing: it is not
 * proposed again in a later package, yet its evidence stays, and changed content comes
 * back. For every mapping, through the review route and a real later package.
 */
class EhiRejectedProposalTest extends TestCase
{
    use EhiIntakeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhi();
        Storage::fake('phr_exports');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownEhi();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: class-string<Model>, 4: string, 5: array<string, string>, 6: array<string, string>}>
     *                                                                                                                                                                     mapping, the root table, its key, the record model, a table holding it, a row match, a change
     */
    public static function mappings(): array
    {
        return [
            'immunization' => ['immunization', 'IMMUNE', ['IMMUNE_ID' => '601'], PhrImmunization::class, 'IMMUNE', ['IMMUNE_ID' => '601'], ['LOT' => 'SYN-LOT-CHANGED']],
            'document' => ['document', 'HNO_INFO', ['NOTE_ID' => '88001'], PhrDocument::class, 'HNO_INFO', ['NOTE_ID' => '88001'], ['NOTE_DESC' => 'Synthetic retitled note']],
            'lab result' => ['lab_result', 'ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'], PhrLabResult::class, 'ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['ORD_VALUE' => 'Light growth']],
            'encounter' => ['encounter', 'PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'], PhrOfficeVisit::class, 'PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'], ['VISIT_PROV_ID_PROV_NAME' => 'SYNTHETIC SECOND PROVIDER']],
        ];
    }

    /**
     * @param  array<string, string>  $key
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $match
     * @param  array<string, string>  $change
     */
    #[DataProvider('mappings')]
    public function test_an_unchanged_row_a_person_rejected_is_not_proposed_again_and_its_evidence_stays(string $mapping, string $table, array $key, string $model, string $changedTable, array $match, array $change): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $first = $this->previewed($this->package($mapping)->build(), $owner, $patient);
        $rejected = $this->subject($first, $mapping, $key);
        $this->decide($owner, $patient, $rejected, 'reject');
        self::assertSame(0, $model::query()->count());

        // A later export, different as a package, saying the same thing about this row.
        $second = $this->previewed($this->package($mapping)->build()->with('README.txt', "Synthetic later readme\n"), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);

        self::assertNotSame($first->id, $second->id);
        self::assertSame(0, PhrEhiProposal::query()->where('package_id', $second->id)->where('mapping', $mapping)->where('subject_key', EhiMappingContext::keyDigest($key))->count(), 'not proposed again');
        self::assertSame(1, PhrEhiFinding::query()->where('package_id', $second->id)->where('code', EhiFindingCode::PREVIOUSLY_REJECTED)->count());

        // Rejecting hides nothing: the row is still accounted for and kept, and the decision stands.
        $member = PhrEhiPackageMember::query()->where('package_id', $second->id)->where('table_name', $table)->sole();
        $rows = PhrEhiOccurrence::query()->where('member_id', $member->id)
            ->whereIn('revision_id', fn ($query) => $query->select('revision_id')->from('phr_ehi_proposal_revisions')->where('proposal_id', $rejected->id))->get();
        self::assertCount(1, $rows);
        self::assertSame(PhrEhiOccurrence::DISPOSITION_RETAINED, $rows->first()->disposition);
        self::assertSame(PhrEhiProposal::STATUS_REJECTED, $rejected->refresh()->status);
        self::assertSame(1, PhrEhiProposalDecision::query()->where('proposal_id', $rejected->id)->where('decision', PhrEhiProposal::DECISION_REJECT)->count());
        $listed = $this->actingAs($owner)->getJson("/api/phr/patients/{$patient->id}/ehi/packages/{$first->id}/proposals?status=rejected")->assertOk()->json('proposals');
        self::assertSame([$rejected->id], array_column($listed, 'id'), 'the rejected proposal can still be read');
        $status = $this->actingAs($owner)->getJson("/api/phr/patients/{$patient->id}/ehi/packages/{$second->id}")->assertOk()->json('package');
        self::assertSame(1, $status['proposals']['previously_rejected'], 'and the later package says it set one aside');

        // It no longer waits on any review surface.
        $items = collect($this->actingAs($owner)->getJson("/api/phr/patients/{$patient->id}/review")->assertOk()->json('groups'))->pluck('items')->flatten(1);
        self::assertSame([], $items->where('type', 'ehi_proposal')->filter(fn (array $item): bool => $item['proposal']['status'] === PhrEhiProposal::STATUS_REJECTED)->values()->all());
        self::assertNotContains(EhiMappingContext::keyDigest($key), PhrEhiProposal::query()->where('package_id', $second->id)->where('status', PhrEhiProposal::STATUS_PENDING)->pluck('subject_key')->all());

        // Changed content comes back for review.
        $changed = $this->package($mapping)->changing($changedTable, $match, $change)->build();
        $third = $this->previewed($changed, $owner, $patient, 'package-3', PhrEhiSource::query()->sole()->id);
        $back = $this->subject($third, $mapping, $key);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $back->status);
        self::assertSame(PhrEhiProposal::KIND_CREATE, $back->kind);
        $this->decide($owner, $patient, $back, 'accept');
        self::assertSame(1, $model::query()->count());
    }

    public function test_decisions_in_one_second_are_ordered_by_when_they_were_made_not_by_which_preview_came_first(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $key = ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'];
        $match = ['ORDER_PROC_ID' => '8102', 'LINE' => '1'];
        $rejectedLater = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', $match, ['ORD_VALUE' => 'Light growth']);
        $older = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $sourceId = PhrEhiSource::query()->sole()->id;
        $newer = $this->previewed($rejectedLater->build(), $owner, $patient, 'package-2', $sourceId);

        // The newer package's proposal is rejected, and then the older one's accepted, within one second.
        Carbon::setTestNow(Carbon::parse('2026-03-05 10:00:00'));
        $this->decide($owner, $patient, $this->subject($newer, 'lab_result', $key), 'reject');
        $this->decide($owner, $patient, $this->subject($older, 'lab_result', $key), 'accept');

        $third = $this->previewed($rejectedLater->build()->with('README.txt', "Synthetic later readme\n"), $owner, $patient, 'package-3', $sourceId);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $this->subject($third, 'lab_result', $key)->kind, 'the acceptance came last, so this is reconciled and not set aside');
    }

    /**
     * Two packages linked to one earlier record within a second: what the source last
     * said is what the later link accepted, whichever package was previewed first.
     *
     * @param  array<string, string>  $key
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $legacy
     * @param  array<string, string>  $match
     * @param  array<string, string>  $first
     * @param  array<string, string>  $second
     */
    #[DataProvider('linkedBaselines')]
    public function test_the_source_baseline_is_the_last_decision_made_not_the_last_previewed(string $mapping, array $key, string $model, array $legacy, string $table, array $match, array $first, array $second, string $field): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $earlier = $model::query()->forceCreate(['patient_id' => $patient->id, 'user_id' => $owner->id, 'review_status' => PhrReviewStatus::CONFIRMED, ...$legacy]);
        $older = $this->previewed($this->package($mapping)->changing($table, $match, $first)->build(), $owner, $patient);
        $sourceId = PhrEhiSource::query()->sole()->id;
        $newer = $this->previewed($this->package($mapping)->changing($table, $match, $second)->build(), $owner, $patient, 'package-2', $sourceId);
        $link = fn (PhrEhiProposal $proposal) => $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$proposal->id}/review", ['decision' => 'link_existing', 'preview_digest' => $proposal->preview_digest, 'link_id' => $earlier->id])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-03-05 10:00:00'));
        $link($this->subject($newer, $mapping, $key));
        $link($this->subject($older, $mapping, $key));

        // The source now says what the newer package said. Compared with the last link, that changed.
        $third = $this->previewed($this->package($mapping)->changing($table, $match, $second)->build()->with('README.txt', "Synthetic later readme\n"), $owner, $patient, 'package-3', $sourceId);
        $proposal = $this->subject($third, $mapping, $key);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $proposal->kind);
        self::assertContains($field, $proposal->proposed_json['changed']);
    }

    /** @return array<string, array{0: string, 1: array<string, string>, 2: class-string<Model>, 3: array<string, mixed>, 4: string, 5: array<string, string>, 6: array<string, string>, 7: array<string, string>, 8: string}> */
    public static function linkedBaselines(): array
    {
        return [
            'lab result' => ['lab_result', ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'], PhrLabResult::class, ['import_source' => 'manual', 'analyte' => 'SYNTHETIC CULTURE', 'value' => 'No growth', 'unit' => 'stored unit', 'result_datetime' => '2024-03-06 10:00:00'], 'ORDER_RESULTS', ['ORDER_PROC_ID' => '8102', 'LINE' => '1'], ['REFERENCE_UNIT' => 'first unit'], ['REFERENCE_UNIT' => 'second unit'], 'unit'],
            'encounter' => ['encounter', ['PAT_ENC_CSN_ID' => '7101'], PhrOfficeVisit::class, ['import_source' => 'manual', 'provider_name' => 'stored provider', 'visit_date' => '2024-05-01'], 'PAT_ENC', ['PAT_ENC_CSN_ID' => '7101'], ['VISIT_PROV_ID_PROV_NAME' => 'FIRST PROVIDER'], ['VISIT_PROV_ID_PROV_NAME' => 'SECOND PROVIDER'], 'provider_name'],
        ];
    }

    public function test_a_rejected_update_is_not_proposed_again_and_the_record_keeps_what_it_held(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $key = ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'];
        $match = ['ORDER_PROC_ID' => '8102', 'LINE' => '1'];
        $first = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $this->decide($owner, $patient, $this->subject($first, 'lab_result', $key), 'accept');
        $record = PhrLabResult::query()->where('external_id', 'ORDER_RESULTS:8102:66001:1')->sole();
        $sourceId = PhrEhiSource::query()->sole()->id;

        $changed = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', $match, ['ORD_VALUE' => 'Light growth']);
        $second = $this->previewed($changed->build(), $owner, $patient, 'package-2', $sourceId);
        $update = $this->subject($second, 'lab_result', $key);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $update->kind);
        $this->decide($owner, $patient, $update, 'reject');

        $again = $this->previewed($changed->build()->with('README.txt', "Synthetic later readme\n"), $owner, $patient, 'package-3', $sourceId);
        self::assertSame(0, PhrEhiProposal::query()->where('package_id', $again->id)->where('subject_key', EhiMappingContext::keyDigest($key))->count());
        self::assertSame('No growth', $record->refresh()->value);

        // A further correction is new content, and comes back.
        $corrected = EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', $match, ['ORD_VALUE' => 'Heavy growth']);
        $fourth = $this->previewed($corrected->build(), $owner, $patient, 'package-4', $sourceId);
        self::assertSame(PhrEhiProposal::KIND_UPDATE, $this->subject($fourth, 'lab_result', $key)->kind);
    }

    public function test_only_the_latest_decision_counts_so_a_row_that_was_later_accepted_is_reconciled_again(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $key = ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'];
        $match = ['ORDER_PROC_ID' => '8102', 'LINE' => '1'];
        $first = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $this->decide($owner, $patient, $this->subject($first, 'lab_result', $key), 'reject');
        $sourceId = PhrEhiSource::query()->sole()->id;

        // Different content, accepted. Then the source goes back to what was rejected.
        $second = $this->previewed(EhiClinicalPackage::withLabs()->changing('ORDER_RESULTS', $match, ['ORD_VALUE' => 'Light growth'])->build(), $owner, $patient, 'package-2', $sourceId);
        $this->decide($owner, $patient, $this->subject($second, 'lab_result', $key), 'accept');
        $third = $this->previewed(EhiClinicalPackage::withLabs()->build()->with('README.txt', "Synthetic later readme\n"), $owner, $patient, 'package-3', $sourceId);

        self::assertSame(PhrEhiProposal::KIND_UPDATE, $this->subject($third, 'lab_result', $key)->kind, 'that record now differs from the source');
    }

    public function test_another_installation_says_nothing_about_this_ones_rejection(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $key = ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'];
        $first = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $this->decide($owner, $patient, $this->subject($first, 'lab_result', $key), 'reject');

        // A separately declared source, whose keys can repeat this one's by chance.
        $package = $this->ehiIntakeDirectory(EhiClinicalPackage::withLabs()->build()->with('README.txt', "Synthetic other readme\n"), $patient, (int) $owner->id, 'package-2')->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, null, 'Synthetic Other Clinic');
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        self::assertSame(PhrEhiProposal::STATUS_PENDING, $this->subject($package->refresh(), 'lab_result', $key)->status);
    }

    public function test_a_pending_proposal_elsewhere_is_set_aside_by_the_next_preview_after_a_rejection(): void
    {
        [$owner, $patient] = $this->ownerAndPatient();
        $key = ['ORDER_PROC_ID' => '8102', 'ORD_DATE_REAL' => '66001', 'LINE' => '1'];
        $first = $this->previewed(EhiClinicalPackage::withLabs()->build(), $owner, $patient);
        $second = $this->previewed(EhiClinicalPackage::withLabs()->build()->with('README.txt', "Synthetic later readme\n"), $owner, $patient, 'package-2', PhrEhiSource::query()->sole()->id);
        $pending = $this->subject($second, 'lab_result', $key);
        self::assertSame(PhrEhiProposal::STATUS_PENDING, $pending->status);

        $this->decide($owner, $patient, $this->subject($first, 'lab_result', $key), 'reject');
        app(EhiProposalService::class)->preview($second, $patient, (int) $owner->id);

        self::assertSame(PhrEhiProposal::STATUS_SUPERSEDED, $pending->refresh()->status);
    }

    private function package(string $mapping): EhiClinicalPackage
    {
        return match ($mapping) {
            'lab_result' => EhiClinicalPackage::withLabs(),
            'encounter' => EhiClinicalPackage::withEncounters(),
            default => EhiClinicalPackage::standard(),
        };
    }

    /** @return array{0: User, 1: PhrPatient} */
    private function ownerAndPatient(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => PhrPatientUserAccess::LEVEL_OWNER, 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient];
    }

    private function previewed(EhiSyntheticPackage $build, User $owner, PhrPatient $patient, string $name = 'package', ?int $sourceId = null): PhrEhiPackage
    {
        $package = $this->ehiIntakeDirectory($build, $patient, (int) $owner->id, $name)->package;
        $package = app(EhiBindingService::class)->bind($package, $patient, (int) $owner->id, $sourceId, $sourceId === null ? 'Synthetic Clinic' : null);
        app(EhiProposalService::class)->preview($package, $patient, (int) $owner->id);

        return $package->refresh();
    }

    /** @param  array<string, string>  $key */
    private function subject(PhrEhiPackage $package, string $mapping, array $key): PhrEhiProposal
    {
        return PhrEhiProposal::query()->where('package_id', $package->id)->where('mapping', $mapping)->where('subject_key', EhiMappingContext::keyDigest($key))->firstOrFail();
    }

    private function decide(User $owner, PhrPatient $patient, PhrEhiProposal $proposal, string $decision): void
    {
        $proposal->refresh();
        $this->actingAs($owner)->postJson("/api/phr/patients/{$patient->id}/ehi/proposals/{$proposal->id}/review", ['decision' => $decision === 'accept' && $proposal->kind === PhrEhiProposal::KIND_UPDATE ? 'approve_update' : $decision, 'preview_digest' => $proposal->preview_digest])->assertOk();
    }
}
