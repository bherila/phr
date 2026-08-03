<?php

namespace App\Services\PHR\NativeBackup;

final readonly class NativeBackupBuildResult
{
    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public string $path,
        public int $fileSize,
        public string $sha256,
        public array $manifest,
        public array $counts,
    ) {}
}
