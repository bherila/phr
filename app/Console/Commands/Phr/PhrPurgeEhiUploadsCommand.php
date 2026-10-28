<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrEhiUpload;
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
                        if (! $uploads->discardArchive($upload)) {
                            $failed++;

                            continue;
                        }
                        if (! in_array($upload->status, PhrEhiUpload::TERMINAL_STATUSES, true)) {
                            $upload->update([
                                'status' => PhrEhiUpload::STATUS_FAILED,
                                'failure_reason' => 'upload_expired',
                                'completed_at' => $upload->completed_at ?? now(),
                            ]);
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
}
