<?php

namespace App\Console\Commands;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomUpload;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use App\Support\Storage\PhrStorageMap;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PhrDicomGarbageCollect extends Command
{
    protected $signature = 'phr:dicom:gc {--pending-hours=6 : Mark pending uploads older than this as failed and reclaim their objects}
                                         {--dry-run : Report what would be deleted without making changes}';

    protected $description = 'Reclaim stale derived volumes and PHR DICOM objects that are orphaned or belong to stuck pending uploads.';

    public function __construct(private readonly DicomUploadProcessor $uploadProcessor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pendingHours = max(1, (int) $this->option('pending-hours'));
        $cutoff = Carbon::now()->subHours($pendingHours);

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        $stalePending = $this->failStalePendingUploads($cutoff, $dryRun);
        $staleDerivedVolumes = $this->reclaimStaleDerivedVolumes($dryRun);
        $orphanedObjects = $this->reclaimOrphanedObjects($dryRun);

        $this->info(sprintf(
            'PHR DICOM gc complete: %d pending upload(s) finalized, %d stale derived volume(s) reclaimed, %d storage object(s) reclaimed.',
            $stalePending,
            $staleDerivedVolumes,
            $orphanedObjects,
        ));

        return self::SUCCESS;
    }

    /**
     * Walk uploads that have been STATUS_PENDING longer than the cutoff and
     * route them through DicomUploadProcessor::failUpload so cleanup is
     * identical to the in-request rollback path.
     */
    private function failStalePendingUploads(Carbon $cutoff, bool $dryRun): int
    {
        $count = 0;

        $uploads = PhrDicomUpload::query()
            ->where('status', PhrDicomUpload::STATUS_PENDING)
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($uploads as $upload) {
            $this->line("  Stale pending upload #{$upload->id}");
            $count++;

            if ($dryRun) {
                continue;
            }

            $this->uploadProcessor->failUpload($upload, 'Marked failed by phr:dicom:gc after pending timeout.');
        }

        return $count;
    }

    private function reclaimStaleDerivedVolumes(bool $dryRun): int
    {
        $currentPipelineVersion = (int) config('phr.volume_cache_pipeline_version', 1);
        $staleArtifacts = PhrDicomFile::query()
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->get()
            ->filter(function (PhrDicomFile $file) use ($currentPipelineVersion): bool {
                $pipelineVersion = ($file->metadata_json ?? [])['pipeline_version'] ?? null;

                return ! is_numeric($pipelineVersion) || (int) $pipelineVersion !== $currentPipelineVersion;
            });

        foreach ($staleArtifacts as $artifact) {
            $this->line("  Stale derived volume #{$artifact->id}");

            if ($dryRun) {
                continue;
            }

            try {
                $this->uploadProcessor->disk()->delete($artifact->r2_key);
                $artifact->delete();
            } catch (Throwable $error) {
                $this->error("    Failed to delete derived volume #{$artifact->id} (".$error::class.').');
            }
        }

        return $staleArtifacts->count();
    }

    /**
     * List storage keys with no matching phr_dicom_files row and delete them.
     *
     * Canonical and legacy roots come from the same map as storage:prune so a
     * key-shape rollout cannot silently create a namespace this scheduled
     * collector never visits.
     */
    private function reclaimOrphanedObjects(bool $dryRun): int
    {
        $disk = $this->uploadProcessor->disk();
        $keys = [];

        foreach (PhrStorageMap::disks()[DicomUploadProcessor::DISK] as $prefix) {
            try {
                $keys = [...$keys, ...$disk->allFiles($prefix)];
            } catch (Throwable $error) {
                $this->error("Failed to list DICOM storage [{$prefix}]: ".$error->getMessage());
            }
        }

        if ($keys === []) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($keys, 500) as $keyBatch) {
            $knownSet = array_flip(PhrDicomFile::query()
                ->whereIn('r2_key', $keyBatch)
                ->pluck('r2_key')
                ->all());
            if (Schema::hasTable('phr_blob_migrations')) {
                $protectedMigrations = DB::table('phr_blob_migrations')
                    ->where('storage_disk', DicomUploadProcessor::DISK)
                    ->whereNull('legacy_deleted_at')
                    ->where(function ($query) use ($keyBatch): void {
                        $query->whereIn('source_key', $keyBatch)
                            ->orWhereIn('destination_key', $keyBatch);
                    })
                    ->get(['source_key', 'destination_key']);
                foreach ($protectedMigrations as $migration) {
                    foreach ([(string) $migration->source_key, (string) $migration->destination_key] as $protectedKey) {
                        if (in_array($protectedKey, $keyBatch, true)) {
                            $knownSet[$protectedKey] = true;
                        }
                    }
                }
            }

            foreach ($keyBatch as $key) {
                if (isset($knownSet[$key])) {
                    continue;
                }

                // Orphan rows have no internal artifact id to report, and the key
                // can contain an original filename. Keep command/cron output PHI-free.
                $this->line('  Orphan DICOM object detected.');
                $count++;

                if ($dryRun) {
                    continue;
                }

                try {
                    $disk->delete($key);
                } catch (Throwable $error) {
                    $this->error('    Failed to delete orphan DICOM object ('.$error::class.').');
                }
            }
        }

        return $count;
    }
}
