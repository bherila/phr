<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiFinding;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\Import\Ehi\EhiPreviewBudget;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\EhiFakeMapping;
use Tests\Support\EhiProjectionFixtures;
use Tests\TestCase;

class EhiResourceBoundariesTest extends TestCase
{
    use EhiProjectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEhiProjection();
        EhiFindingFloodMapping::$events = 10;
        EhiFindingFloodMapping::$drafts = false;
    }

    protected function tearDown(): void
    {
        $this->tearDownEhiProjection();
        parent::tearDown();
    }

    public function test_findings_are_bounded_while_collected_and_report_exact_omissions(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        config(['phr.ehi_mappings' => [EhiFindingFloodMapping::class], 'phr.ehi_max_findings_per_member' => 2]);
        EhiFindingFloodMapping::$events = 10000;
        $preview = app(EhiProposalService::class)->preview($package, $patient, $owner->id);
        self::assertSame(['synthetic_preview_event' => 2], $preview->findings);
        $findings = PhrEhiFinding::query()->where('detail_json->stage', 'preview')->orderBy('id')->get();
        self::assertCount(3, $findings);
        self::assertSame([0, 1], $findings->take(2)->map(fn ($row) => $row->detail_json['sequence'])->all());
        self::assertSame(9998, $findings->last()->detail_json['omitted']);
        self::assertNotNull($findings->first()->line_number);
        self::assertSame('info', $findings->first()->severity);
    }

    public function test_invalid_codes_are_rejected_even_after_the_finding_cap(): void
    {
        [, $patient, $package] = $this->fixture();
        config(['phr.ehi_max_findings_per_member' => 1]);
        $context = new EhiMappingContext($package, $patient, PhrEhiSource::findOrFail($package->source_id));
        $context->finding('valid');
        $context->finding('also_valid');
        self::assertCount(1, $context->findings());
        self::assertSame(1, $context->omittedFindings());
        $this->expectException(\LogicException::class);
        $context->finding('INVALID CODE');
    }

    public function test_failed_mapping_keeps_findings_from_the_last_completed_preview(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        config(['phr.ehi_mappings' => [EhiFindingFloodMapping::class]]);
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $before = PhrEhiFinding::query()->where('detail_json->stage', 'preview')->get()->toArray();
        EhiFindingFloodMapping::$events = 1;
        config(['phr.ehi_mappings' => [EhiFindingFloodMapping::class, EhiLaterFailureMapping::class]]);
        $service = app(EhiProposalService::class); // a worker with the new registry
        try {
            $service->preview($package->refresh(), $patient, $owner->id);
            self::fail('The mapping must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('synthetic preview failure', $exception->getMessage());
        }
        self::assertSame($before, PhrEhiFinding::query()->where('detail_json->stage', 'preview')->get()->toArray());
        self::assertFalse($package->refresh()->previewCompleted());
    }

    public function test_finding_replacement_and_completion_roll_back_together(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        config(['phr.ehi_mappings' => [EhiFindingFloodMapping::class]]);
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $before = PhrEhiFinding::query()->where('detail_json->stage', 'preview')->get()->toArray();
        $fail = true;
        DB::listen(static function (QueryExecuted $event) use (&$fail): void {
            if ($fail && str_starts_with($event->sql, 'insert into "phr_ehi_findings"')) {
                $fail = false;
                throw new \RuntimeException('synthetic insert failure');
            }
        });
        try {
            $service->preview($package->refresh(), $patient, $owner->id);
            self::fail('Publication must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('synthetic insert failure', $exception->getMessage());
        }
        self::assertSame($before, PhrEhiFinding::query()->where('detail_json->stage', 'preview')->get()->toArray());
        self::assertSame(1, $package->refresh()->preview_generation_completed);
        self::assertFalse($package->previewCompleted());
    }

    public function test_preview_refusal_preserves_evidence_and_invalidates_pending_review(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        $service = app(EhiProposalService::class);
        $service->preview($package, $patient, $owner->id);
        $evidence = DB::table('phr_ehi_revisions')->count();
        config(['phr.ehi_preview_max_rows' => 1]);
        try {
            $service->preview($package->refresh(), $patient, $owner->id);
            self::fail('The work set must be refused.');
        } catch (EhiProjectionRefused $exception) {
            self::assertSame('resource_limit', $exception->reason);
        }
        self::assertSame($evidence, DB::table('phr_ehi_revisions')->count());
        self::assertFalse($package->refresh()->previewCompleted());
        config(['phr.ehi_preview_max_rows' => 10000, 'phr.ehi_preview_max_bytes' => 1]);
        $this->expectException(EhiProjectionRefused::class);
        (new EhiPreviewBudget)->assertPatient($patient->id);
    }

    public function test_preview_reserves_proposal_dependencies_before_committing_a_chunk(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        config(['phr.ehi_mappings' => [EhiFindingFloodMapping::class], 'phr.ehi_preview_max_rows' => 100]);
        EhiFindingFloodMapping::$events = 250;
        EhiFindingFloodMapping::$drafts = true;
        try {
            app(EhiProposalService::class)->preview($package, $patient, $owner->id);
            self::fail('The prospective chunk must be refused before its rows are inserted.');
        } catch (EhiProjectionRefused $exception) {
            self::assertSame('resource_limit', $exception->reason);
        }
        self::assertSame(0, PhrEhiProposal::query()->where('package_id', $package->id)->count());
        self::assertFalse($package->refresh()->previewCompleted());
    }

    private function fixture(): array
    {
        $owner = $this->createUser();
        $patient = $this->ehiPatient($owner);
        PhrPatientUserAccess::create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'access_level' => 'owner', 'granted_by_user_id' => $owner->id, 'granted_at' => now()]);

        return [$owner, $patient, $this->boundPackage($this->itemPackage($this->standardItems()), $patient, $owner)];
    }
}

class EhiFindingFloodMapping implements EhiMapping
{
    public static int $events = 10;

    public static bool $drafts = false;

    public function name(): string
    {
        return 'finding_flood';
    }

    public function version(): string
    {
        return 'flood-1';
    }

    public function expectedKeys(): array
    {
        return [EhiFakeMapping::TABLE => ['ITEM_ID', 'LINE']];
    }

    public function propose(EhiMappingContext $context): iterable
    {
        $revision = $context->revisionByKey(EhiFakeMapping::TABLE, ['ITEM_ID' => '100', 'LINE' => '1']);
        for ($i = 0; $i < self::$events; $i++) {
            $context->finding('synthetic_preview_event', $revision, ['sequence' => $i, 'severity' => 'info']);
            if (self::$drafts && $revision !== null) {
                yield new EhiProposalDraft(hash('sha256', 'synthetic-budget-'.$i), PhrEhiProposal::KIND_RETAIN, [$revision->id]);
            }
        }

        return [];
    }

    public function apply(PhrEhiProposal $proposal, EhiApplyContext $context): EhiApplyOutcome
    {
        throw new \LogicException('no clinical proposals');
    }
}

/** Fails after the first mapping has finished and prepared its new findings. */
final class EhiLaterFailureMapping extends EhiFindingFloodMapping
{
    public function name(): string
    {
        return 'later_failure';
    }

    public function propose(EhiMappingContext $context): iterable
    {
        throw new \RuntimeException('synthetic preview failure');
    }
}
