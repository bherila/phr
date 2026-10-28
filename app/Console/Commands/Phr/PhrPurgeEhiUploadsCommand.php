<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiUpload;
use App\Services\PHR\Import\Ehi\EhiUploadRefused;
use App\Services\PHR\Import\Ehi\EhiUploadService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Throwable;

#[Signature('phr:ehi-uploads:purge')]
#[Description('Delete staged EHI upload archives that expired or whose intake already finished')]
final class PhrPurgeEhiUploadsCommand extends BasePhrCommand
{
    /** An expired upload still marked processing is left to a live worker for this long. */
    private const int PROCESSING_GRACE_HOURS = 2;

    public function handle(EhiUploadService $uploads): int
    {
        $purged = 0;
        $failed = 0;
        PhrEhiUpload::query()
            ->whereNotNull('storage_path')
            ->where(function ($query): void {
                // Finished uploads whose archive delete failed, and expired ones
                // that never finished. A live intake job's archive is kept.
                $query->whereIn('status', PhrEhiUpload::TERMINAL_STATUSES)
                    ->orWhere(function ($expired): void {
                        $expired->where('expires_at', '<=', now())
                            ->where(function ($idle): void {
                                $idle->where('status', '<>', PhrEhiUpload::STATUS_PROCESSING)
                                    ->orWhere('updated_at', '<=', now()->subHours(self::PROCESSING_GRACE_HOURS));
                            });
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($expired) use (&$purged, &$failed, $uploads): void {
                foreach ($expired as $upload) {
                    try {
                        if (! $this->expire($upload)) {
                            // Changed since it was read (an intake job claimed
                            // it): not this run's to delete.
                            continue;
                        }
                        if (! $uploads->discardArchive($upload->refresh())) {
                            $failed++;

                            continue;
                        }
                        $purged++;
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            });

        $this->info("EHI upload purge: purged={$purged} failed={$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Mark an unfinished upload expired, only if it is still in the state it was
     * read in and still expired, so a job that claimed it in between keeps it.
     * A finished upload cannot change, and needs no transition.
     */
    private function expire(PhrEhiUpload $upload): bool
    {
        if (in_array($upload->status, PhrEhiUpload::TERMINAL_STATUSES, true)) {
            return true;
        }
        $query = PhrEhiUpload::query()
            ->whereKey($upload->id)
            ->where('status', $upload->status)
            ->where('expires_at', '<=', now());
        if ($upload->status === PhrEhiUpload::STATUS_PROCESSING) {
            $query->where('updated_at', '<=', now()->subHours(self::PROCESSING_GRACE_HOURS));
        }

        return $query->update([
            'status' => PhrEhiUpload::STATUS_FAILED,
            'failure_reason' => EhiUploadRefused::EXPIRED,
            'completed_at' => $upload->completed_at ?? now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
