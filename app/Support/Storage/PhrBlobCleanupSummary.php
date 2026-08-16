<?php

namespace App\Support\Storage;

final class PhrBlobCleanupSummary
{
    public int $examined = 0;

    public int $retained = 0;

    public int $planned = 0;

    public int $deleted = 0;

    public int $alreadyDeleted = 0;

    public int $failed = 0;

    public int $bytes = 0;

    public function record(string $status, int $bytes = 0): void
    {
        $this->examined++;
        $this->bytes += max(0, $bytes);

        match ($status) {
            'retained' => $this->retained++,
            'planned' => $this->planned++,
            'deleted' => $this->deleted++,
            'already_deleted' => $this->alreadyDeleted++,
            default => $this->failed++,
        };
    }
}
