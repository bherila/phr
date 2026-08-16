<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrNativeRestoreAttempt;
use App\Services\PHR\NativeBackup\PhrNativeRestoreService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('phr:native-restores:purge')]
#[Description('Delete expired native restore source archives while retaining metadata-only audit rows')]
final class PhrPurgeNativeRestoresCommand extends BasePhrCommand
{
    public function handle(PhrNativeRestoreService $restoreService): int
    {
        $purged = 0;
        $failed = 0;
        PhrNativeRestoreAttempt::query()
            ->whereNotNull('source_storage_path')
            ->where('expires_at', '<=', now())
            ->where(function ($query): void {
                // A live worker gets twice the one-hour job timeout. Lost queue
                // work cannot protect a multi-gigabyte source forever.
                $query->whereNotIn('status', [
                    PhrNativeRestoreAttempt::STATUS_PROCESSING,
                    PhrNativeRestoreAttempt::STATUS_FINALIZING,
                    PhrNativeRestoreAttempt::STATUS_PREVIEW_PROCESSING,
                ])->orWhere('updated_at', '<=', now()->subHours(2));
            })
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use (&$purged, &$failed, $restoreService): void {
                foreach ($attempts as $attempt) {
                    try {
                        if ($attempt->status === PhrNativeRestoreAttempt::STATUS_FINALIZING) {
                            if (! $restoreService->finalizePendingRestore((int) $attempt->id)) {
                                $failed++;

                                continue;
                            }
                            $attempt->refresh();
                        }
                        if (! Storage::disk($attempt->source_storage_disk)->delete((string) $attempt->source_storage_path)) {
                            $failed++;

                            continue;
                        }
                        $updates = ['source_storage_path' => null];
                        if (! in_array($attempt->status, [PhrNativeRestoreAttempt::STATUS_COMPLETED, PhrNativeRestoreAttempt::STATUS_FAILED], true)) {
                            $updates += [
                                'status' => PhrNativeRestoreAttempt::STATUS_FAILED,
                                'failure_category' => 'preview_expired',
                                'completed_at' => now(),
                            ];
                        }
                        $attempt->update($updates);
                        $purged++;
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            });

        $this->info("Native restore source purge: purged={$purged} failed={$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
