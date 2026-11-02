<?php

namespace Tests\Support;

/**
 * Evaluates config/filesystems.php under a chosen environment. Laravel's env repository
 * reads putenv() as well as the superglobals, and CI fills all three from a .env copied
 * from .env.example, so every storage setting is cleared first and restored after.
 */
final class FilesystemConfig
{
    private const array SETTINGS = [
        'PHR_STORAGE', 'PHR_STORAGE_S3_ACCESS_KEY_ID', 'PHR_STORAGE_S3_SECRET_ACCESS_KEY', 'PHR_STORAGE_S3_REGION',
        'PHR_STORAGE_S3_BUCKET', 'PHR_STORAGE_S3_ENDPOINT', 'PHR_STORAGE_S3_USE_PATH_STYLE_ENDPOINT',
        'PHR_DICOM_DISK_DRIVER', 'PHR_DICOM_DISK_ROOT', 'PHR_DICOM_DISK_SERVE', 'PHR_DICOM_R2_ACCESS_KEY_ID', 'PHR_DICOM_R2_SECRET_ACCESS_KEY',
        'PHR_DICOM_R2_REGION', 'PHR_DICOM_R2_BUCKET', 'PHR_DICOM_R2_URL', 'PHR_DICOM_R2_ENDPOINT', 'PHR_DICOM_R2_USE_PATH_STYLE_ENDPOINT',
        'S3_DISK_DRIVER', 'S3_DISK_ROOT', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET', 'AWS_URL',
        'AWS_S3_ENDPOINT', 'AWS_USE_PATH_STYLE_ENDPOINT',
        'PHR_UPLOADS_DISK_DRIVER', 'PHR_UPLOADS_ACCESS_KEY_ID', 'PHR_UPLOADS_SECRET_ACCESS_KEY', 'PHR_UPLOADS_REGION', 'PHR_UPLOADS_BUCKET',
        'PHR_UPLOADS_ENDPOINT', 'PHR_UPLOADS_USE_PATH_STYLE_ENDPOINT',
        'PHR_DOCUMENTS_DISK_ROOT', 'PHR_EXPORTS_DISK_ROOT',
    ];

    /**
     * @param  array<string, string>  $settings
     * @return array<string, mixed>
     */
    public static function load(array $settings): array
    {
        $original = [];
        foreach (self::SETTINGS as $key) {
            $value = getenv($key);
            $original[$key] = $value === false ? ($_ENV[$key] ?? $_SERVER[$key] ?? null) : $value;
            self::apply($key, $settings[$key] ?? null);
        }

        try {
            return require base_path('config/filesystems.php');
        } finally {
            foreach ($original as $key => $value) {
                self::apply($key, is_string($value) ? $value : null);
            }
        }
    }

    private static function apply(string $key, ?string $value): void
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
}
