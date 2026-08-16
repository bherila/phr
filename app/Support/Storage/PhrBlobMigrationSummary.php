<?php

namespace App\Support\Storage;

final class PhrBlobMigrationSummary
{
    public int $examined = 0;

    public int $planned = 0;

    public int $migrated = 0;

    public int $alreadyCanonical = 0;

    public int $skipped = 0;

    public int $failed = 0;

    public int $bytes = 0;

    public function record(string $status, int $bytes = 0): void
    {
        $this->examined++;
        $this->bytes += max(0, $bytes);

        match ($status) {
            'planned', 'planned_reuse', 'recovery_planned' => $this->planned++,
            'migrated', 'migrated_reuse', 'recovered_legacy' => $this->migrated++,
            'already_canonical', 'verified_canonical' => $this->alreadyCanonical++,
            'active_upload' => $this->skipped++,
            default => $this->failed++,
        };
    }
}
