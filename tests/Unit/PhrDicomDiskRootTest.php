<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The phr_dicom disk can run on either "local" or "s3", and Flysystem reads
 * `root` differently for each: a filesystem path for local, an object-key
 * prefix for s3. A local-shaped default therefore turns into a key prefix the
 * moment the driver is s3 — every R2 lookup misses, and the DICOM instance-file
 * endpoint answers 404 for images that are sitting in the bucket. Nothing in
 * the app surfaces that; the disk reports "no such object" exactly as it would
 * for genuinely absent data.
 */
class PhrDicomDiskRootTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function loadFilesystemsConfig(?string $driver, ?string $root = null): array
    {
        $original = [
            'PHR_DICOM_DISK_DRIVER' => $_SERVER['PHR_DICOM_DISK_DRIVER'] ?? null,
            'PHR_DICOM_DISK_ROOT' => $_SERVER['PHR_DICOM_DISK_ROOT'] ?? null,
        ];

        foreach (['PHR_DICOM_DISK_DRIVER' => $driver, 'PHR_DICOM_DISK_ROOT' => $root] as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
            } else {
                $_SERVER[$key] = $value;
                $_ENV[$key] = $value;
            }
        }

        try {
            return require base_path('config/filesystems.php');
        } finally {
            foreach ($original as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key], $_ENV[$key]);
                } else {
                    $_SERVER[$key] = $value;
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    public function test_object_store_driver_gets_no_key_prefix(): void
    {
        $config = $this->loadFilesystemsConfig('s3');

        $this->assertSame('', $config['disks']['phr_dicom']['root']);
    }

    public function test_default_driver_gets_no_key_prefix(): void
    {
        $config = $this->loadFilesystemsConfig(null);

        $this->assertSame('s3', $config['disks']['phr_dicom']['driver']);
        $this->assertSame('', $config['disks']['phr_dicom']['root']);
    }

    public function test_local_driver_falls_back_to_a_local_storage_path(): void
    {
        $config = $this->loadFilesystemsConfig('local');

        $this->assertSame('local', $config['disks']['phr_dicom']['driver']);
        $this->assertSame(storage_path('app/private/phr-dicom'), $config['disks']['phr_dicom']['root']);
    }

    public function test_explicit_root_still_wins_for_either_driver(): void
    {
        foreach (['s3', 'local'] as $driver) {
            $config = $this->loadFilesystemsConfig($driver, 'custom/prefix');

            $this->assertSame('custom/prefix', $config['disks']['phr_dicom']['root']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadS3DiskConfig(?string $driver, ?string $root = null): array
    {
        // Laravel's env repository reads putenv() as well as the superglobals, and CI
        // populates all three by copying .env.example to .env. Clearing only $_SERVER and
        // $_ENV would leave a stale getenv() value and quietly test the wrong thing.
        $keys = ['S3_DISK_DRIVER' => $driver, 'S3_DISK_ROOT' => $root];
        $original = [];

        foreach (array_keys($keys) as $key) {
            $value = getenv($key);
            $original[$key] = $value === false ? null : $value;
        }

        foreach ($keys as $key => $value) {
            $this->applyEnv($key, $value);
        }

        try {
            return (require base_path('config/filesystems.php'))['disks']['s3'];
        } finally {
            foreach ($original as $key => $value) {
                $this->applyEnv($key, $value);
            }
        }
    }

    private function applyEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
    }

    /**
     * Unlike phr_dicom, the GenAI staging disk defaults to local. This app has never set
     * the AWS_* vars anywhere, so an "s3" default resolves to a driver with no region and
     * throws on first use — which is exactly what production did until 2026-08-02.
     */
    public function test_staging_disk_defaults_to_local_storage(): void
    {
        $disk = $this->loadS3DiskConfig(null);

        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/private/s3-blobs'), $disk['root']);
    }

    public function test_staging_disk_takes_no_key_prefix_on_the_object_store(): void
    {
        $disk = $this->loadS3DiskConfig('s3');

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('', $disk['root'], 'A storage_path() here would prefix every object key and 404 every read.');
    }

    public function test_staging_disk_honours_an_explicit_root_on_either_driver(): void
    {
        foreach (['s3', 'local'] as $driver) {
            $this->assertSame('custom/prefix', $this->loadS3DiskConfig($driver, 'custom/prefix')['root']);
        }
    }

    /**
     * A bare `S3_DISK_ROOT=` in a .env is set-to-empty, not unset, so it beats an env()
     * default. On the local driver that roots Flysystem at the process working directory
     * and staged files land outside storage/ entirely.
     *
     * This is not hypothetical: CI builds its .env with `cp .env.example .env`, so one
     * empty line in the example file is enough to reach every test run and every fresh
     * deployment that starts from it.
     */
    public function test_staging_disk_ignores_an_empty_root_override(): void
    {
        $this->assertSame(
            storage_path('app/private/s3-blobs'),
            $this->loadS3DiskConfig('local', '')['root'],
        );
        $this->assertSame('', $this->loadS3DiskConfig('s3', '')['root']);
    }

    public function test_staging_disk_ignores_an_empty_driver_override(): void
    {
        $disk = $this->loadS3DiskConfig('');

        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/private/s3-blobs'), $disk['root']);
    }
}
