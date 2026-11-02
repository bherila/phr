<?php

namespace Tests\Support;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

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

    /**
     * The disks exactly as config/filesystems.php builds them for PHR_STORAGE=s3, so the
     * options a live run proves are the ones an installation gets.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function configuredDisks(): array
    {
        $settings = [
            'PHR_STORAGE' => 's3',
            'PHR_STORAGE_S3_ACCESS_KEY_ID' => (string) getenv('PHR_TEST_S3_KEY'),
            'PHR_STORAGE_S3_SECRET_ACCESS_KEY' => (string) getenv('PHR_TEST_S3_SECRET'),
            'PHR_STORAGE_S3_REGION' => (string) getenv('PHR_TEST_S3_REGION') ?: 'garage',
            'PHR_STORAGE_S3_BUCKET' => (string) getenv('PHR_TEST_S3_BUCKET'),
            'PHR_STORAGE_S3_ENDPOINT' => (string) getenv('PHR_TEST_S3_ENDPOINT'),
            'PHR_STORAGE_S3_USE_PATH_STYLE_ENDPOINT' => 'true',
        ];

        return FilesystemConfig::load($settings)['disks'];
    }

    /** Point a disk at the store, under a prefix only this run uses. */
    public static function useDisk(string $disk, string $run): void
    {
        $config = self::configuredDisks()[$disk];
        $config['root'] = $run.'/'.$config['root'];
        config(["filesystems.disks.{$disk}" => $config]);
        Storage::forgetDisk($disk);
    }

    public static function client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => (string) getenv('PHR_TEST_S3_REGION') ?: 'garage',
            'endpoint' => (string) getenv('PHR_TEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => (string) getenv('PHR_TEST_S3_KEY'), 'secret' => (string) getenv('PHR_TEST_S3_SECRET')],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }

    /** @return list<string> every whole key in the bucket under a prefix, read past the disks */
    public static function keys(string $prefix): array
    {
        $keys = [];
        foreach (self::client()->getPaginator('ListObjectsV2', ['Bucket' => (string) getenv('PHR_TEST_S3_BUCKET'), 'Prefix' => $prefix]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $keys[] = (string) $object['Key'];
            }
        }

        return $keys;
    }

    /** Abort what a run left open, which a listing of objects does not show. */
    public static function abortUploads(string $prefix): void
    {
        $bucket = (string) getenv('PHR_TEST_S3_BUCKET');
        foreach (self::client()->getPaginator('ListMultipartUploads', ['Bucket' => $bucket, 'Prefix' => $prefix]) as $page) {
            foreach ($page['Uploads'] ?? [] as $upload) {
                self::client()->abortMultipartUpload(['Bucket' => $bucket, 'Key' => (string) $upload['Key'], 'UploadId' => (string) $upload['UploadId']]);
            }
        }
    }

    /** Remove everything a run left, so a shared bucket does not grow. */
    public static function purge(string $prefix): void
    {
        foreach (self::keys($prefix) as $key) {
            self::client()->deleteObject(['Bucket' => (string) getenv('PHR_TEST_S3_BUCKET'), 'Key' => $key]);
        }
    }
}
