<?php

namespace Tests\Feature\PHR\Ehi;

use App\Models\PhrEhiFinding;
use App\Models\PhrEhiPreviewRun;
use App\Models\PhrEhiProposal;
use App\Models\PhrEhiSource;
use App\Models\PhrLabResult;
use App\Models\PhrPatientUserAccess;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Services\PHR\Import\Ehi\EhiImportPresenter;
use App\Services\PHR\Import\Ehi\EhiPreviewBudget;
use App\Services\PHR\Import\Ehi\EhiPreviewLimit;
use App\Services\PHR\Import\Ehi\EhiPreviewQueue;
use App\Services\PHR\Import\Ehi\EhiProjectionRefused;
use App\Services\PHR\Import\Ehi\EhiProjectionService;
use App\Services\PHR\Import\Ehi\EhiProposalService;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiApplyOutcome;
use App\Services\PHR\Import\Ehi\Mapping\EhiMapping;
use App\Services\PHR\Import\Ehi\Mapping\EhiMappingContext;
use App\Services\PHR\Import\Ehi\Mapping\EhiProposalDraft;
use App\Services\PHR\ResourceByteSize;
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
        EhiFindingFloodMapping::$timeoutAfterChunk = false;
        EhiFindingFloodMapping::$starts = 0;
        EhiFindingFloodMapping::$afterFirstChunk = null;
    }

    protected function tearDown(): void
    {
        EhiFindingFloodMapping::$afterFirstChunk = null;
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

    public function test_time_refusal_keeps_projection_checkpoint_but_retry_restarts_proposal_generation(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        config(['phr.ehi_mappings' => [EhiFindingFloodMapping::class]]);
        EhiFindingFloodMapping::$events = 201;
        EhiFindingFloodMapping::$drafts = true;
        EhiFindingFloodMapping::$timeoutAfterChunk = true;
        $queue = app(EhiPreviewQueue::class);
        $run = $queue->queue($package, $patient, $owner->id);
        $queue->run($run->id);
        $dto = app(EhiImportPresenter::class)->previewRun($run->refresh());
        self::assertSame('resource_limit', $dto['failure_reason']);
        self::assertSame(['resource_limit' => 'time'], $dto['failure_details']);
        self::assertFalse($package->refresh()->previewCompleted());
        self::assertSame(200, PhrEhiProposal::query()->where('package_id', $package->id)->count());
        $checkpoint = $package->checkpoint_json['projection'];
        $prefixIds = PhrEhiProposal::query()->where('package_id', $package->id)->orderBy('id')->pluck('id')->all();
        EhiFindingFloodMapping::$timeoutAfterChunk = false;
        config(['phr.ehi_preview_max_seconds' => 240]);
        $next = $queue->queue($package, $patient, $owner->id);
        $queue->run($next->id);
        self::assertSame(PhrEhiPreviewRun::STATUS_COMPLETED, $next->refresh()->status);
        self::assertSame(2, EhiFindingFloodMapping::$starts, 'Each generation enumerates from the beginning, without a cursor.');
        self::assertTrue($package->refresh()->previewCompleted());
        self::assertSame(2, $package->preview_generation_started);
        self::assertSame($checkpoint, $package->checkpoint_json['projection']);
        self::assertSame($prefixIds, PhrEhiProposal::query()->where('package_id', $package->id)->orderBy('id')->limit(200)->pluck('id')->all());
        self::assertSame(201, PhrEhiProposal::query()->where('package_id', $package->id)->count());
        self::assertNull(app(EhiImportPresenter::class)->previewRun($next)['failure_details']);
    }

    public function test_row_and_byte_refusals_keep_the_legacy_code_with_closed_capacity_details(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        foreach ([[1, 8388608], [10000, 1]] as [$rows, $bytes]) {
            config(['phr.ehi_preview_max_rows' => $rows, 'phr.ehi_preview_max_bytes' => $bytes]);
            $queue = app(EhiPreviewQueue::class);
            $run = $queue->queue($package->refresh(), $patient, $owner->id);
            $queue->run($run->id);
            $dto = app(EhiImportPresenter::class)->previewRun($run->refresh());
            self::assertSame('resource_limit', $dto['failure_reason']);
            self::assertSame(['resource_limit' => 'capacity'], $dto['failure_details']);
            self::assertFalse($package->refresh()->previewCompleted());
        }
        foreach ([null, ['failure_details' => ['resource_limit' => 'PRIVATE_source_value', 'raw' => 'PRIVATE']]] as $legacy) {
            $run->update(['result_json' => $legacy]);
            $dto = app(EhiImportPresenter::class)->previewRun($run->refresh());
            self::assertSame('resource_limit', $dto['failure_reason']);
            self::assertNull($dto['failure_details']);
            self::assertStringNotContainsString('PRIVATE', json_encode($dto, JSON_THROW_ON_ERROR));
        }
    }

    public function test_chunk_reservation_preserves_exact_row_and_one_over_boundaries(): void
    {
        [, $patient] = $this->fixture();
        $usage = $this->patientUsage($patient->id);
        $budget = new EhiPreviewBudget;
        $evidence = $budget->measurePatient($patient->id);
        config(['phr.ehi_preview_max_rows' => $usage['rows'] + 3]);
        $budget->reservePatient($evidence, 3, 0);

        $this->expectException(EhiProjectionRefused::class);
        $budget->reservePatient($evidence, 4, 0);
    }

    public function test_chunk_reservation_preserves_exact_raw_byte_and_one_over_boundaries(): void
    {
        [$owner, $patient] = $this->fixture();
        PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'analyte' => 'Synthetic UTF-8', 'value' => 'é']);
        $usage = $this->patientUsage($patient->id);
        $budget = new EhiPreviewBudget;
        $evidence = $budget->measurePatient($patient->id);
        config(['phr.ehi_preview_max_bytes' => $usage['bytes'] + 7]);
        $budget->reservePatient($evidence, 0, 7);

        $this->expectException(EhiProjectionRefused::class);
        $budget->reservePatient($evidence, 0, 8);
    }

    public function test_chunk_reservation_remeasures_clinical_utf8_growth_after_evidence_measurement(): void
    {
        [$owner, $patient] = $this->fixture();
        $lab = PhrLabResult::query()->create(['patient_id' => $patient->id, 'user_id' => $owner->id, 'analyte' => 'Synthetic UTF-8', 'value' => 'é']);
        $usage = $this->patientUsage($patient->id);
        $budget = new EhiPreviewBudget;
        $evidence = $budget->measurePatient($patient->id);
        // Existing clinical updates do not all lock the patient. Under READ
        // COMMITTED the old second scan could see this two-byte growth, so a
        // cached evidence measurement must still re-read the clinical tables.
        $lab->update(['value' => 'éé']);
        config(['phr.ehi_preview_max_bytes' => $usage['bytes'] + 1]);

        $this->expectException(EhiProjectionRefused::class);
        $budget->reservePatient($evidence, 0, 0);
    }

    public function test_chunk_reservation_keeps_other_patients_out_of_the_budget(): void
    {
        [$owner, $patient] = $this->fixture();
        $usage = $this->patientUsage($patient->id);
        $other = $this->ehiPatient($owner);
        PhrLabResult::query()->create(['patient_id' => $other->id, 'user_id' => $owner->id, 'analyte' => 'Synthetic other patient', 'value' => str_repeat('é', 4096)]);
        config(['phr.ehi_preview_max_rows' => $usage['rows'], 'phr.ehi_preview_max_bytes' => $usage['bytes']]);
        $budget = new EhiPreviewBudget;
        $evidence = $budget->measurePatient($patient->id);
        $budget->reservePatient($evidence, 0, 0);

        self::assertSame($patient->id, $evidence->patientId);
    }

    public function test_chunk_reservation_checks_the_original_time_budget_before_reusing_usage(): void
    {
        [, $patient] = $this->fixture();
        $budget = new EhiPreviewBudget;
        $evidence = $budget->measurePatient($patient->id);
        config(['phr.ehi_preview_max_seconds' => -1]);
        try {
            $budget->reservePatient($evidence, 0, 0);
            self::fail('Reusing evidence must still enforce the original clock.');
        } catch (EhiProjectionRefused $exception) {
            self::assertSame(EhiPreviewLimit::Time, $exception->resourceLimit);
        }
    }

    public function test_a_later_chunk_remeasures_evidence_committed_between_patient_guards(): void
    {
        [$owner, $patient, $package] = $this->fixture();
        app(EhiProjectionService::class)->project($package, $owner->id);
        $usage = $this->patientUsage($patient->id);
        $sources = PhrEhiSource::query()->where('patient_id', $patient->id)->count();
        config(['phr.ehi_mappings' => [EhiFindingFloodMapping::class], 'phr.ehi_preview_max_rows' => $usage['rows'] + 402]);
        EhiFindingFloodMapping::$events = 201;
        EhiFindingFloodMapping::$drafts = true;
        EhiFindingFloodMapping::$afterFirstChunk = static function () use ($patient, $owner): void {
            app(PhrPatientArtifactWriteGuard::class)->run($patient->id, static function () use ($patient, $owner): void {
                PhrEhiSource::query()->create([
                    'patient_id' => $patient->id, 'created_by_user_id' => $owner->id,
                    'label' => 'Synthetic concurrent source', 'source_patient_key' => 'SYNTHETIC-second',
                    'dictionary_fingerprint' => hash('sha256', 'synthetic second dictionary'),
                ]);
            });
        };
        try {
            app(EhiProposalService::class)->preview($package, $patient, $owner->id);
            self::fail('The next chunk must reserve against the newly committed evidence.');
        } catch (EhiProjectionRefused $exception) {
            self::assertSame('resource_limit', $exception->reason);
        }
        self::assertSame(200, PhrEhiProposal::query()->where('package_id', $package->id)->count());
        self::assertSame($sources + 1, PhrEhiSource::query()->where('patient_id', $patient->id)->count());
        self::assertFalse($package->refresh()->previewCompleted());
    }

    /** Unbounded aggregate oracle over the deliberately tiny synthetic fixture. */
    private function patientUsage(int $patientId): array
    {
        $rows = 0;
        $bytes = 0;
        $sizes = new ResourceByteSize;
        foreach (EhiPreviewBudget::TABLES as $table) {
            $totals = DB::table($table)->where('patient_id', $patientId)
                ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM('.$sizes->row($table).'), 0) AS byte_count')->first();
            $rows += (int) $totals->row_count;
            $bytes += (int) $totals->byte_count;
        }

        return ['rows' => $rows, 'bytes' => $bytes];
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

    public static bool $timeoutAfterChunk = false;

    public static int $starts = 0;

    public static ?\Closure $afterFirstChunk = null;

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
        self::$starts++;
        $revision = $context->revisionByKey(EhiFakeMapping::TABLE, ['ITEM_ID' => '100', 'LINE' => '1']);
        for ($i = 0; $i < self::$events; $i++) {
            if ($i === 200 && self::$afterFirstChunk !== null) {
                $after = self::$afterFirstChunk;
                self::$afterFirstChunk = null;
                $after();
            }
            if (self::$timeoutAfterChunk && $i === 200) {
                config(['phr.ehi_preview_max_seconds' => -1]);
                $context->budget->checkpoint();
            }
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
