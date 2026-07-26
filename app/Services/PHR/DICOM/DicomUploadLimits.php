<?php

namespace App\Services\PHR\DICOM;

final class DicomUploadLimits
{
    public const int DEFAULT_MAX_DIRECT_FILE_BYTES = 1_073_741_824;

    public static function maxDirectFileBytes(): int
    {
        $configured = config('phr.dicom_max_file_bytes', self::DEFAULT_MAX_DIRECT_FILE_BYTES);
        $bytes = is_numeric($configured) ? (int) $configured : self::DEFAULT_MAX_DIRECT_FILE_BYTES;

        return $bytes > 0 ? $bytes : self::DEFAULT_MAX_DIRECT_FILE_BYTES;
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
