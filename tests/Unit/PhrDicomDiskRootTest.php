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
}
