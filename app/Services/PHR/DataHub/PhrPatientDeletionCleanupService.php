<?php

namespace App\Services\PHR\DataHub;

use App\Models\PhrPatientDeletion;
use App\Models\PhrPatientDeletionArtifact;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class PhrPatientDeletionCleanupService
{
    /** @var list<string> */
    private const array ALLOWED_DISKS = ['phr_documents', 'phr_dicom', 'phr_exports'];

    public function cleanup(int $deletionId): void
    {
        $deletion = PhrPatientDeletion::query()->find($deletionId);
        if ($deletion === null || $deletion->status === PhrPatientDeletion::STATUS_COMPLETED) {
            return;
        }
        $deletion->update([
            'status' => PhrPatientDeletion::STATUS_PROCESSING,
            'failure_category' => null,
        ]);

        PhrPatientDeletionArtifact::query()
            ->where('deletion_id', $deletionId)
            ->orderBy('id')
            ->chunkById(100, function ($artifacts): void {
                foreach ($artifacts as $artifact) {
                    $this->cleanupArtifact($artifact);
                }
            });

        $remaining = PhrPatientDeletionArtifact::query()->where('deletion_id', $deletionId)->count();
        if ($remaining > 0) {
            $deletion->update([
                'status' => PhrPatientDeletion::STATUS_FAILED,
                'failure_category' => 'storage_cleanup_failed',
            ]);

            throw new RuntimeException('Patient storage cleanup remains incomplete.');
        }

        $deletion->update([
            'status' => PhrPatientDeletion::STATUS_COMPLETED,
            'failure_category' => null,
            'completed_at' => now(),
        ]);
    }

    public function markQueueFailure(int $deletionId): void
    {
        DB::transaction(function () use ($deletionId): void {
            $deletion = PhrPatientDeletion::query()->whereKey($deletionId)->lockForUpdate()->first();
            if ($deletion === null || $deletion->status === PhrPatientDeletion::STATUS_COMPLETED) {
                return;
            }

            $deletion->update([
                'status' => PhrPatientDeletion::STATUS_FAILED,
                // Domain cleanup failures are more actionable than the queue's
                // terminal callback and must survive the final retry.
                'failure_category' => $deletion->failure_category ?? 'queue_failure',
            ]);
        });
    }

    private function cleanupArtifact(PhrPatientDeletionArtifact $artifact): void
    {
        $artifact->update([
            'attempt_count' => $artifact->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);

        try {
            if (! in_array($artifact->storage_disk, self::ALLOWED_DISKS, true)
                || ! $this->validStoredKey($artifact->storage_key)
                || ! hash_equals($artifact->storage_key_hash, hash('sha256', $artifact->storage_key))
                || $this->hasLiveReference($artifact->storage_disk, $artifact->storage_key)) {
                $artifact->update(['status' => PhrPatientDeletionArtifact::STATUS_FAILED]);

                return;
            }

            $disk = Storage::disk($artifact->storage_disk);
            if ($disk->exists($artifact->storage_key)) {
                $deleted = $disk->delete($artifact->storage_key);
                if (! $deleted || $this->stillExists($disk, $artifact->storage_key)) {
                    $artifact->update(['status' => PhrPatientDeletionArtifact::STATUS_FAILED]);

                    return;
                }
            }

            DB::transaction(function () use ($artifact): void {
                PhrPatientDeletionArtifact::query()->whereKey($artifact->id)->delete();
            });
        } catch (Throwable) {
            $artifact->update(['status' => PhrPatientDeletionArtifact::STATUS_FAILED]);
        }
    }

    private function stillExists(Filesystem $disk, string $key): bool
    {
        return $disk->exists($key);
    }

    private function hasLiveReference(string $disk, string $key): bool
    {
        if (DB::table('phr_documents')
            ->where('storage_disk', $disk)->where('storage_path', $key)->exists()) {
            return true;
        }
        if ($disk === 'phr_dicom') {
            if (DB::table('phr_dicom_files')->where('r2_key', $key)->exists()) {
                return true;
            }

            // Pending upload prefixes protect objects that may exist before their
            // individual file rows. Compare in PHP to avoid wildcard semantics.
            foreach (DB::table('phr_dicom_uploads')->where('status', 'pending')->pluck('r2_prefix') as $prefix) {
                $normalized = rtrim((string) $prefix, '/');
                if ($key === $normalized || str_starts_with($key, $normalized.'/')) {
                    return true;
                }
            }
        }
        if (DB::table('phr_exports')
            ->where('storage_disk', $disk)->where('storage_path', $key)->exists()
            || DB::table('phr_native_backups')->where('storage_disk', $disk)->where('storage_path', $key)->exists()) {
            return true;
        }

        return DB::table('phr_blob_migrations')
            ->where('storage_disk', $disk)
            ->where(function ($query) use ($key): void {
                $query->where('source_key', $key)->orWhere('destination_key', $key);
            })->exists();
    }

    private function validStoredKey(string $key): bool
    {
        if ($key === '' || strlen($key) > 1024 || str_starts_with($key, '/') || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            return false;
        }

        $segments = explode('/', str_replace('\\', '/', $key));

        return ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && ! in_array('..', $segments, true);
    }
}
