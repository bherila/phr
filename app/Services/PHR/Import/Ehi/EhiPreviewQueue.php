<?php

namespace App\Services\PHR\Import\Ehi;

use App\Jobs\PHR\PreviewEhiPackageJob;
use App\Models\PhrEhiPackage;
use App\Models\PhrEhiPreviewRun;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs EhiProposalService::preview -- the same service as `phr:ehi:preview` --
 * from a queued job, because a preview can outlast a proxied web request.
 *
 * The preconditions the projection would refuse first (preserved and accounted,
 * bound) are checked when the preview is requested, so those refusals stay
 * immediate. While a run of a package is queued or running, requesting another
 * returns it rather than queuing a second.
 */
final class EhiPreviewQueue
{
    /** A queued or running run untouched this long is presumed lost and no longer blocks a new one. */
    private const int STALE_HOURS = 2;

    /** A lost run closed when a new one replaced it; its job, if it ever runs, does nothing. */
    public const string FAILURE_STALE = 'stale';

    public function __construct(
        private readonly PhrPatientAccessService $access,
        private readonly PhrPatientArtifactWriteGuard $writeGuard,
        private readonly EhiProposalService $proposals,
    ) {}

    /**
     * @throws EhiProjectionRefused not_ready or not_bound; nothing was queued
     * @throws AuthorizationException
     */
    public function queue(PhrEhiPackage $package, PhrPatient $patient, int $actorUserId): PhrEhiPreviewRun
    {
        [$run, $created] = $this->writeGuard->run((int) $patient->id, function (PhrPatient $locked) use ($package, $actorUserId): array {
            $this->access->ensureCanWrite($locked, $actorUserId);
            $current = PhrEhiPackage::query()->whereKey($package->id)->where('patient_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($current->preservation_status !== PhrEhiPackage::PRESERVATION_PRESERVED
                || $current->accounting_status !== PhrEhiPackage::ACCOUNTING_ACCOUNTED) {
                throw new EhiProjectionRefused(EhiProjectionRefused::NOT_READY, 'the package is not preserved and accounted');
            }
            if ($current->binding_status !== PhrEhiPackage::BINDING_BOUND || $current->source_id === null) {
                throw new EhiProjectionRefused(EhiProjectionRefused::NOT_BOUND, 'the package is not bound to a declared source');
            }

            $active = PhrEhiPreviewRun::query()
                ->where('package_id', $current->id)
                ->whereIn('status', PhrEhiPreviewRun::ACTIVE_STATUSES)
                ->where('updated_at', '>', now()->subHours(self::STALE_HOURS))
                ->latest('id')
                ->first();
            if ($active !== null) {
                return [$active, false];
            }
            // Close lost runs before replacing them, so a late job of one finds
            // nothing to claim instead of previewing beside the new run.
            PhrEhiPreviewRun::query()
                ->where('package_id', $current->id)
                ->whereIn('status', PhrEhiPreviewRun::ACTIVE_STATUSES)
                ->update(['status' => PhrEhiPreviewRun::STATUS_FAILED, 'failure_reason' => self::FAILURE_STALE, 'completed_at' => now(), 'updated_at' => now()]);

            return [PhrEhiPreviewRun::query()->create([
                'patient_id' => $locked->id,
                'package_id' => $current->id,
                'requested_by_user_id' => $actorUserId,
                'status' => PhrEhiPreviewRun::STATUS_QUEUED,
            ]), true];
        });

        if ($created) {
            try {
                PreviewEhiPackageJob::dispatch($run->id);
            } catch (Throwable) {
                $this->markFailed($run->id, 'queue_failed');
            }
        }

        return $run->refresh();
    }

    /** Run a queued preview. Called by the job; every outcome is recorded on the run. */
    public function run(int $runId): void
    {
        $claimed = PhrEhiPreviewRun::query()
            ->whereKey($runId)
            ->where('status', PhrEhiPreviewRun::STATUS_QUEUED)
            ->update(['status' => PhrEhiPreviewRun::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        $run = PhrEhiPreviewRun::query()->find($runId);
        if ($claimed === 0 || $run === null) {
            return;
        }

        $patient = PhrPatient::query()->find($run->patient_id);
        $package = PhrEhiPackage::query()->where('patient_id', $run->patient_id)->find($run->package_id);
        if ($patient === null || $package === null || $run->requested_by_user_id === null) {
            $this->finish($run, EhiIntakeService::FAILURE_ACCESS_REVOKED);

            return;
        }
        if ($patient->archived_at !== null) {
            $this->finish($run, EhiUploadRefused::PATIENT_ARCHIVED);

            return;
        }

        try {
            $preview = $this->proposals->preview($package, $patient, $run->requested_by_user_id);
        } catch (EhiProjectionRefused $refused) {
            $this->finish($run, $refused->reason);

            return;
        } catch (AuthorizationException|ModelNotFoundException) {
            $this->finish($run, EhiIntakeService::FAILURE_ACCESS_REVOKED);

            return;
        } catch (Throwable $failure) {
            // Never log the message: it could carry a source or clinical detail.
            Log::warning('EHI preview run failed unexpectedly.', ['run_id' => $run->id, 'exception' => $failure::class]);
            $this->finish($run, EhiIntakeService::FAILURE_UNEXPECTED);

            return;
        }

        $run->update([
            'status' => PhrEhiPreviewRun::STATUS_COMPLETED,
            'failure_reason' => null,
            'result_json' => [
                'counts' => $preview->counts,
                'statuses' => $preview->statuses,
                'findings' => $preview->findings,
                'preview_digest' => $preview->digest,
                'replayed' => $preview->projection->replayed,
            ],
            'completed_at' => now(),
        ]);
    }

    public function markFailed(int $runId, string $reason): void
    {
        PhrEhiPreviewRun::query()
            ->whereKey($runId)
            ->whereIn('status', PhrEhiPreviewRun::ACTIVE_STATUSES)
            ->update(['status' => PhrEhiPreviewRun::STATUS_FAILED, 'failure_reason' => $reason, 'completed_at' => now(), 'updated_at' => now()]);
    }

    private function finish(PhrEhiPreviewRun $run, string $reason): void
    {
        $run->update(['status' => PhrEhiPreviewRun::STATUS_FAILED, 'failure_reason' => $reason, 'completed_at' => now()]);
    }
}
