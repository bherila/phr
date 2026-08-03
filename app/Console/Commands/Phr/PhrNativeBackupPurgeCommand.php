<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrNativeBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

#[Signature('phr:native-backups:purge {--dry-run : Preview deletions} {--expired-before= : Delete backups expiring before this date-time}')]
#[Description('Purge expired native PHR backups and their stored archives')]
class PhrNativeBackupPurgeCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $beforeOption = $this->option('expired-before');
        $before = is_string($beforeOption) && trim($beforeOption) !== '' ? Carbon::parse($beforeOption) : now();
        $dryRun = (bool) $this->option('dry-run');
        $matched = 0;
        $failed = 0;

        PhrNativeBackup::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $before)
            ->orderBy('id')
            ->chunkById(100, function ($backups) use ($dryRun, &$matched, &$failed): void {
                foreach ($backups as $backup) {
                    $matched++;
                    if ($dryRun) {
                        continue;
                    }
                    if ($backup->storage_path !== null && ! Storage::disk($backup->storage_disk)->delete($backup->storage_path)) {
                        $failed++;

                        continue;
                    }
                    $backup->delete();
                }
            });

        $this->info(($dryRun ? 'Matched' : 'Purged')." {$matched} expired native backup(s); failures={$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
