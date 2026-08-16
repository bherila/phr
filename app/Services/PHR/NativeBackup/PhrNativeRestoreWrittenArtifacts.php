<?php

namespace App\Services\PHR\NativeBackup;

final class PhrNativeRestoreWrittenArtifacts
{
    /** @var list<array{0: string, 1: string}> */
    public array $items = [];

    public function add(string $disk, string $path): void
    {
        $this->items[] = [$disk, $path];
    }
}
