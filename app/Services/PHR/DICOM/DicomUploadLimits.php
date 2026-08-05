<?php

namespace App\Services\PHR\DICOM;

final class DicomUploadLimits
{
    public const int DEFAULT_MAX_DIRECT_FILE_BYTES = 1_073_741_824;

    /** Laravel receives each file as multipart form data, where validation caps it at 200 MiB. */
    public const int MAX_MULTIPART_FILE_KILOBYTES = 204_800;

    public const int MAX_MULTIPART_FILE_BYTES = self::MAX_MULTIPART_FILE_KILOBYTES * 1024;

    public static function maxDirectFileBytes(): int
    {
        $configured = config('phr.dicom_max_file_bytes', self::DEFAULT_MAX_DIRECT_FILE_BYTES);
        $bytes = is_numeric($configured) ? (int) $configured : self::DEFAULT_MAX_DIRECT_FILE_BYTES;

        return $bytes > 0 ? $bytes : self::DEFAULT_MAX_DIRECT_FILE_BYTES;
    }

    /**
     * The truthful limit for the current upload route.
     *
     * `phr.dicom_max_file_bytes` remains the configurable product ceiling, but this
     * endpoint is not a storage-direct upload: PHP and Laravel receive the body first.
     * Never advertise more than the request validator can accept.
     */
    public static function maxMultipartFileBytes(): int
    {
        return self::maxMultipartFileKilobytes() * 1024;
    }

    /**
     * Laravel's file max rule is expressed in whole KiB. Normalize the product
     * ceiling once so validation, API metadata, and error text describe one limit.
     */
    public static function maxMultipartFileKilobytes(): int
    {
        return max(1, intdiv(
            min(self::maxDirectFileBytes(), self::MAX_MULTIPART_FILE_BYTES),
            1024,
        ));
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $index => $unit) {
            if ($value < 1024 || $index === array_key_last($units)) {
                $isWholeNumber = abs($value - round($value)) < 0.00001;
                $decimals = ($isWholeNumber || $value >= 10) ? 0 : 1;

                return number_format($value, $decimals).' '.$unit;
            }

            $value /= 1024;
        }

        return "{$bytes} B";
    }
}
