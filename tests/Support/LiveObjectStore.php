<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The opt-in S3-compatible store for live storage tests, the same shape of opt-in as
 * PHR_TEST_DATABASE. PHR_TEST_S3_ENDPOINT selects it; tests/bootstrap.php refuses any
 * endpoint that is not on this machine, so no run can reach a real bucket. Every test
 * gets its own key prefix, so parallel workers share one bucket without seeing each other.
 */
final class LiveObjectStore
{
    public static function configured(): bool
    {
        return (string) getenv('PHR_TEST_S3_ENDPOINT') !== '';
    }

    /** @return array<string, mixed> The settings every PHR disk gets from one PHR_STORAGE=s3 store. */
    public static function diskConfig(string $root): array
    {
        return [
            'driver' => 's3',
            'key' => (string) getenv('PHR_TEST_S3_KEY'),
            'secret' => (string) getenv('PHR_TEST_S3_SECRET'),
            'region' => (string) getenv('PHR_TEST_S3_REGION') ?: 'garage',
            'bucket' => (string) getenv('PHR_TEST_S3_BUCKET'),
            'endpoint' => (string) getenv('PHR_TEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'root' => $root,
            'stream_reads' => true,
            'retain_visibility' => false,
            'throw' => false,
            'report' => false,
        ];
    }

    /** Point a disk at the store under a prefix no other test uses. */
    public static function useDisk(string $disk, string $root, ?string $run = null): string
    {
        $prefix = ($run ?? 'run-'.Str::lower((string) Str::ulid())).'/'.$root;
        config(["filesystems.disks.{$disk}" => self::diskConfig($prefix)]);
        Storage::forgetDisk($disk);

        return $prefix;
    }
}
