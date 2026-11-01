<?php

namespace App\Services\PHR\Storage;

use App\Models\PhrPatient;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Best-effort admission limits. No filesystem walks, object listings, or per-blob ledger. */
final class BlobStorageQuota
{
    public const int MAX_LIMIT_BYTES = 1_000_000_000_000_000;

    public const int USAGE_TTL_SECONDS = 60;

    /** @return array{global_limit_bytes: ?int, default_user_limit_bytes: ?int} */
    public function settings(): array
    {
        $row = DB::table('phr_storage_settings')->where('id', 1)->first();

        return ['global_limit_bytes' => isset($row->global_limit_bytes) ? (int) $row->global_limit_bytes : null,
            'default_user_limit_bytes' => isset($row->default_user_limit_bytes) ? (int) $row->default_user_limit_bytes : null];
    }

    public function assertForPatient(PhrPatient $patient, int $additionalBytes): void
    {
        $this->assertForOwner((int) $patient->owner_user_id, $additionalBytes);
    }

    public function assertForOwner(int $ownerId, int $additionalBytes): void
    {
        $settings = $this->settings();
        $limit = $this->userLimit($ownerId);
        if ($settings['global_limit_bytes'] !== null
            && $additionalBytes > $settings['global_limit_bytes'] - $this->usedBytes()) {
            throw ValidationException::withMessages(['storage' => 'The application storage limit has been reached. Ask an administrator to increase it.']);
        }
        if ($limit !== null && $additionalBytes > $limit - $this->usedBytes($ownerId)) {
            throw ValidationException::withMessages(['storage' => 'The record owner’s storage limit has been reached. Ask an administrator to increase it.']);
        }
    }

    public function userLimit(int $ownerId): ?int
    {
        $row = DB::table('phr_user_storage_limits')->where('user_id', $ownerId)->first();

        return ($row->unlimited ?? false) ? null : (isset($row->limit_bytes) ? (int) $row->limit_bytes : $this->settings()['default_user_limit_bytes']);
    }

    /** Includes soft-deleted records whose blobs still exist; sharing never charges the delegate. */
    public function usedBytes(?int $ownerId = null): int
    {
        $stored = Cache::remember($this->cacheKey($ownerId), self::USAGE_TTL_SECONDS, function () use ($ownerId): int {
            $bytes = 0;
            foreach ([
                ['phr_documents', 'byte_size', 'storage_path'],
                ['phr_dicom_files', 'file_size_bytes', 'r2_key'],
                ['phr_ehi_blobs', 'byte_size', 'storage_path'],
                ['phr_exports', 'file_size_bytes', 'storage_path'],
                ['phr_native_backups', 'file_size_bytes', 'storage_path'],
            ] as [$table, $size, $path]) {
                $bytes += (int) $this->owned($table, $ownerId)->whereNotNull($table.'.'.$path)->sum($table.'.'.$size);
            }
            $bytes += (int) DB::table('phr_native_restore_attempts')->whereNotNull('source_storage_path')
                ->when($ownerId !== null, fn (Builder $q) => $q->where('actor_user_id', $ownerId))->sum('source_file_size_bytes');

            return $bytes;
        });
        // Authorizations are cheap indexed aggregates, read fresh so sequential URL requests
        // cannot bypass a cached estimate. Concurrent admissions remain deliberately approximate.
        $signed = $this->owned('phr_document_uploads', $ownerId)
            ->where('expires_at', '>', now())
            ->where(fn (Builder $q) => $q->whereIn('status', ['pending', 'receiving', 'uploaded'])->orWhere('transport', 's3'))
            ->sum('byte_size');
        $ehi = $this->owned('phr_ehi_uploads', $ownerId)->whereNotNull('storage_path')->sum('file_size_bytes');

        return (int) $stored + (int) $signed + (int) $ehi;
    }

    private function cacheKey(?int $ownerId): string
    {
        return 'phr.blob-usage.v1.'.($ownerId ?? 'global');
    }

    private function owned(string $table, ?int $ownerId): Builder
    {
        return DB::table($table)->when($ownerId !== null, fn (Builder $query) => $query
            ->whereIn($table.'.patient_id', DB::table('phr_patients')->select('id')->where('owner_user_id', $ownerId)));
    }
}
