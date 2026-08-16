<?php

namespace App\Services\PHR\DataHub;

use App\Jobs\PHR\CleanupDeletedPhrPatientArtifactsJob;
use App\Models\PhrDicomUpload;
use App\Models\PhrNativeBackup;
use App\Models\PhrPatient;
use App\Models\PhrPatientDeletion;
use App\Models\PhrPatientDeletionArtifact;
use App\Services\PHR\NativeBackup\PhrNativeBackupCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds and applies an owner-scoped aggregate deletion plan.
 *
 * The public preview contains only table counts, aggregate bytes, fixed blocker
 * codes, and a digest. Exact storage keys are persisted only after confirmation
 * so cleanup can be retried after the patient graph (and its references) is gone.
 */
final class PhrPatientDeletionService
{
    private const int ACTIVE_BACKUP_LEASE_MINUTES = 15;

    /** @var list<string> */
    private const array ALLOWED_DISKS = ['phr_documents', 'phr_dicom', 'phr_exports'];

    public function preview(PhrPatient $patient): PhrPatientDeletionPlan
    {
        return $this->buildPlan($patient);
    }

    public function delete(
        PhrPatient $patient,
        int $actorUserId,
        string $previewDigest,
        bool $acknowledgeActiveShares,
    ): PhrPatientDeletion {
        $deletion = DB::transaction(function () use ($patient, $actorUserId, $previewDigest, $acknowledgeActiveShares): PhrPatientDeletion {
            $lockedPatient = PhrPatient::query()->whereKey($patient->id)->lockForUpdate()->first();
            if ($lockedPatient === null || (int) $lockedPatient->owner_user_id !== $actorUserId) {
                throw (new ModelNotFoundException)->setModel(PhrPatient::class, [$patient->id]);
            }

            $plan = $this->buildPlan($lockedPatient);
            if (! hash_equals($plan->digest, $previewDigest)) {
                throw new PhrPatientDeletionException('preview_changed');
            }
            if ($plan->blockers !== []) {
                throw new PhrPatientDeletionException($plan->blockers[0]);
            }
            if ($plan->activeShareCount > 0 && ! $acknowledgeActiveShares) {
                throw new PhrPatientDeletionException('active_shares_unacknowledged');
            }

            $deletion = PhrPatientDeletion::query()->create([
                'actor_user_id' => $actorUserId,
                'patient_root_id' => $lockedPatient->id,
                'preview_digest' => $plan->digest,
                'record_counts_json' => $plan->recordCounts,
                'active_share_count' => $plan->activeShareCount,
                'artifact_count' => count($plan->artifacts),
                'artifact_bytes' => $plan->artifactBytes,
                'status' => PhrPatientDeletion::STATUS_PENDING,
                'failure_category' => null,
                'deleted_at' => now(),
            ]);

            foreach ($plan->artifacts as $artifact) {
                PhrPatientDeletionArtifact::query()->create([
                    'deletion_id' => $deletion->id,
                    'storage_disk' => $artifact['disk'],
                    'storage_key' => $artifact['key'],
                    'storage_key_hash' => hash('sha256', $artifact['key']),
                    'expected_bytes' => $artifact['bytes'],
                    'status' => PhrPatientDeletionArtifact::STATUS_PENDING,
                ]);
            }

            $lockedPatient->delete();

            return $deletion;
        }, 3);

        try {
            CleanupDeletedPhrPatientArtifactsJob::dispatch($deletion->id);
        } catch (Throwable) {
            $deletion->refresh();
            if ($deletion->status !== PhrPatientDeletion::STATUS_FAILED) {
                $deletion->update([
                    'status' => PhrPatientDeletion::STATUS_FAILED,
                    'failure_category' => 'dispatch_failed',
                ]);
            }
        }

        return $deletion->refresh();
    }

    public function latestForActorAndPatient(int $actorUserId, int $patientId): ?PhrPatientDeletion
    {
        return PhrPatientDeletion::query()
            ->where('actor_user_id', $actorUserId)
            ->where('patient_root_id', $patientId)
            ->latest('id')
            ->first();
    }

    private function buildPlan(PhrPatient $patient): PhrPatientDeletionPlan
    {
        $patientId = (int) $patient->id;
        $recordCounts = [];
        foreach (PhrNativeBackupCatalog::included() + PhrNativeBackupCatalog::excluded() as $table => $definition) {
            $query = DB::table($table);
            if ($table === 'phr_patients') {
                $query->where('id', $patientId);
            } else {
                $query->where($definition['patient_column'], $patientId);
            }
            $recordCounts[$table] = $query->count();
        }
        ksort($recordCounts);

        $activeShareCount = DB::table('phr_patient_user_access')
            ->where('patient_id', $patientId)
            ->where('user_id', '<>', (int) $patient->owner_user_id)
            ->count();

        $blockers = [];
        if (PhrDicomUpload::query()
            ->where('patient_id', $patientId)
            ->where('status', PhrDicomUpload::STATUS_PENDING)
            ->exists()) {
            // An upload may have stored bytes before its per-file row commits.
            // Wait for finalization (or scheduled stale-upload recovery) so the
            // exact-key cleanup ledger cannot miss an in-flight object.
            $blockers[] = 'dicom_upload_in_progress';
        }
        if (PhrNativeBackup::query()
            ->where('patient_id', $patientId)
            ->where('status', PhrNativeBackup::STATUS_PROCESSING)
            ->where(function ($query): void {
                $query->whereNull('updated_at')
                    ->orWhere('updated_at', '>', now()->subMinutes(self::ACTIVE_BACKUP_LEASE_MINUTES));
            })
            ->exists()) {
            $blockers[] = 'native_backup_in_progress';
        }

        $artifacts = $this->artifacts($patientId, $blockers);
        usort($artifacts, static fn (array $left, array $right): int => [$left['disk'], $left['key']] <=> [$right['disk'], $right['key']]);
        $artifactBytes = array_sum(array_map(static fn (array $artifact): int => $artifact['bytes'] ?? 0, $artifacts));
        sort($blockers);

        $digestArtifacts = array_map(
            static fn (array $artifact): array => [$artifact['disk'], hash('sha256', $artifact['key']), $artifact['bytes']],
            $artifacts,
        );
        $digest = hash('sha256', (string) json_encode([
            'patient_id' => $patientId,
            'record_counts' => $recordCounts,
            'active_share_count' => $activeShareCount,
            'artifacts' => $digestArtifacts,
            'blockers' => $blockers,
        ], JSON_THROW_ON_ERROR));

        return new PhrPatientDeletionPlan(
            $patientId,
            $recordCounts,
            $activeShareCount,
            $artifacts,
            $artifactBytes,
            $blockers,
            $digest,
        );
    }

    /**
     * @param  list<string>  $blockers
     * @return list<array{disk: string, key: string, bytes: int|null}>
     */
    private function artifacts(int $patientId, array &$blockers): array
    {
        /** @var array<string, array{disk: string, key: string, bytes: int|null}> $artifacts */
        $artifacts = [];
        $collect = function (string $disk, ?string $key, ?int $bytes) use ($patientId, &$artifacts, &$blockers): void {
            if ($key === null || $key === '') {
                return;
            }
            if (! in_array($disk, self::ALLOWED_DISKS, true) || ! $this->validStoredKey($key)) {
                $blockers[] = 'invalid_storage_reference';

                return;
            }
            if ($this->referencedByAnotherPatient($disk, $key, $patientId)) {
                $blockers[] = 'shared_storage_reference';

                return;
            }
            $identity = $disk."\0".$key;
            $artifacts[$identity] ??= ['disk' => $disk, 'key' => $key, 'bytes' => $bytes];
            if ($bytes !== null && ($artifacts[$identity]['bytes'] === null || $bytes > $artifacts[$identity]['bytes'])) {
                $artifacts[$identity]['bytes'] = $bytes;
            }
        };

        DB::table('phr_documents')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $collect((string) $row->storage_disk, (string) $row->storage_path, (int) $row->byte_size));
        DB::table('phr_dicom_files')->where('patient_id', $patientId)->whereNotNull('r2_key')
            ->orderBy('id')->each(fn ($row) => $collect('phr_dicom', (string) $row->r2_key, (int) $row->file_size_bytes));
        DB::table('phr_exports')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $collect((string) $row->storage_disk, (string) $row->storage_path, is_numeric($row->file_size_bytes) ? (int) $row->file_size_bytes : null));
        DB::table('phr_native_backups')->where('patient_id', $patientId)->whereNotNull('storage_path')
            ->orderBy('id')->each(fn ($row) => $collect((string) $row->storage_disk, (string) $row->storage_path, is_numeric($row->file_size_bytes) ? (int) $row->file_size_bytes : null));
        DB::table('phr_blob_migrations')->where('patient_id', $patientId)->orderBy('id')->each(function ($row) use ($collect): void {
            if ($row->legacy_deleted_at === null) {
                $collect((string) $row->storage_disk, (string) $row->source_key, (int) $row->source_size_bytes);
            }
            $collect((string) $row->storage_disk, (string) $row->destination_key, (int) $row->source_size_bytes);
        });

        return array_values($artifacts);
    }

    private function referencedByAnotherPatient(string $disk, string $key, int $patientId): bool
    {
        if (DB::table('phr_documents')
            ->where('patient_id', '<>', $patientId)->where('storage_disk', $disk)->where('storage_path', $key)->exists()) {
            return true;
        }
        if ($disk === 'phr_dicom' && DB::table('phr_dicom_files')
            ->where('patient_id', '<>', $patientId)->where('r2_key', $key)->exists()) {
            return true;
        }
        if (DB::table('phr_exports')
            ->where('patient_id', '<>', $patientId)->where('storage_disk', $disk)->where('storage_path', $key)->exists()
            || DB::table('phr_native_backups')->where('patient_id', '<>', $patientId)
                ->where('storage_disk', $disk)->where('storage_path', $key)->exists()) {
            return true;
        }

        return DB::table('phr_blob_migrations')
            ->where('patient_id', '<>', $patientId)
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
