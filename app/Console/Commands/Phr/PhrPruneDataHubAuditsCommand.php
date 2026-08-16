<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrNativeRestoreAttempt;
use App\Models\PhrPatientDeletion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;

#[Signature('phr:data-hub:prune-audits {--dry-run : Report eligible metadata rows without deleting them}')]
#[Description('Prune expired metadata-only Data Hub audit events')]
final class PhrPruneDataHubAuditsCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $successCutoff = now()->subDays((int) config('phr.data_hub_audit_success_retention_days', 2555));
        $failureCutoff = now()->subDays((int) config('phr.data_hub_audit_failure_retention_days', 365));
        $backupSuccess = DB::table('phr_native_backup_audits')->where('outcome', 'succeeded')->where('created_at', '<', $successCutoff);
        $backupFailure = DB::table('phr_native_backup_audits')->where('outcome', '<>', 'succeeded')->where('created_at', '<', $failureCutoff);
        $deletionSuccess = PhrPatientDeletion::query()
            ->where('status', PhrPatientDeletion::STATUS_COMPLETED)
            ->whereDoesntHave('artifacts')
            ->where('completed_at', '<', $successCutoff);
        // Failed deletion events remain the root of retryable artifact rows and are
        // prunable only after no cleanup work remains.
        $deletionFailure = PhrPatientDeletion::query()
            ->where('status', PhrPatientDeletion::STATUS_FAILED)
            ->whereDoesntHave('artifacts')
            ->where('updated_at', '<', $failureCutoff);
        $restoreSuccess = PhrNativeRestoreAttempt::query()
            ->where('status', PhrNativeRestoreAttempt::STATUS_COMPLETED)
            ->whereNull('source_storage_path')
            ->where('completed_at', '<', $successCutoff);
        $restoreFailure = PhrNativeRestoreAttempt::query()
            ->where('status', PhrNativeRestoreAttempt::STATUS_FAILED)
            ->whereNull('source_storage_path')
            ->where('completed_at', '<', $failureCutoff);

        $counts = [
            'backup_success' => (clone $backupSuccess)->count(),
            'backup_failure' => (clone $backupFailure)->count(),
            'deletion_success' => (clone $deletionSuccess)->count(),
            'deletion_failure' => (clone $deletionFailure)->count(),
            'restore_success' => (clone $restoreSuccess)->count(),
            'restore_failure' => (clone $restoreFailure)->count(),
        ];
        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($backupSuccess, $backupFailure, $deletionSuccess, $deletionFailure, $restoreSuccess, $restoreFailure): void {
                $backupSuccess->delete();
                $backupFailure->delete();
                $deletionSuccess->delete();
                $deletionFailure->delete();
                $restoreSuccess->delete();
                $restoreFailure->delete();
            });
        }

        $this->info(sprintf(
            'Data Hub audit prune %s: backup_success=%d backup_failure=%d deletion_success=%d deletion_failure=%d restore_success=%d restore_failure=%d.',
            $this->option('dry-run') ? 'planned' : 'applied',
            $counts['backup_success'],
            $counts['backup_failure'],
            $counts['deletion_success'],
            $counts['deletion_failure'],
            $counts['restore_success'],
            $counts['restore_failure'],
        ));

        return self::SUCCESS;
    }
}
