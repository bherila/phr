<?php

namespace App\Console\Commands\Phr;

use App\Models\PhrExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

#[Signature('phr:exports:purge {--dry-run : Preview deletions} {--expired-before= : Delete exports expiring before this date-time}')]
#[Description('Purge expired generated PHR exports and their stored files')]
class PhrExportPurgeCommand extends BasePhrCommand
{
    public function handle(): int
    {
        $beforeOption = $this->option('expired-before');
        $before = is_string($beforeOption) && trim($beforeOption) !== ''
            ? Carbon::parse($beforeOption)
            : now();
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        PhrExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $before)
            ->orderBy('id')
            ->chunkById(100, function ($exports) use ($dryRun, &$count): void {
                foreach ($exports as $export) {
                    $count++;
                    if ($dryRun) {
                        $this->line("Would purge export {$export->id}: {$export->storage_path}");

                        continue;
                    }

                    if ($export->storage_path) {
                        Storage::disk($export->storage_disk)->delete($export->storage_path);
                    }
                    $export->delete();
                }
            });

        $this->info(($dryRun ? 'Matched' : 'Purged')." {$count} expired export(s).");

        return self::SUCCESS;
    }
}
