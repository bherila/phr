<?php

namespace App\Services\PHR\NativeBackup;

use App\Models\PhrNativeRestoreAttempt;

final class PhrNativeRestorePresenter
{
    /** @return array<string, mixed> */
    public function payload(PhrNativeRestoreAttempt $attempt): array
    {
        return [
            'id' => (int) $attempt->id,
            'format' => PhrNativeBackupCatalog::FORMAT,
            'schema_version' => $attempt->schema_version,
            'status' => $attempt->status,
            'source_file_size_bytes' => (int) $attempt->source_file_size_bytes,
            'uploaded_bytes' => (int) $attempt->uploaded_bytes,
            'chunk_size_bytes' => (int) config('phr.native_restore_chunk_bytes'),
            'target' => $attempt->patient_native_id === null ? null : ($attempt->target_patient_root_id === null ? 'new_patient' : 'existing_patient'),
            'tables' => $attempt->plan_counts_json['tables'] ?? [],
            'artifacts' => $attempt->plan_counts_json['artifacts'] ?? ['create' => 0, 'skip' => 0, 'block' => 0, 'bytes' => 0],
            'access_grant_count' => (int) $attempt->access_grant_count,
            'restore_access_grants' => (bool) $attempt->restore_access_grants,
            'blockers' => $attempt->plan_counts_json['blockers'] ?? [],
            'plan_digest' => $attempt->plan_digest,
            'confirmation_text' => 'RESTORE',
            'failure_category' => $attempt->failure_category,
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'expires_at' => $attempt->expires_at->toIso8601String(),
        ];
    }
}
