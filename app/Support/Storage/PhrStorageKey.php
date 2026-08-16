<?php

namespace App\Support\Storage;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Canonical relative keys for durable PHR-owned blobs.
 *
 * Disk roots already separate documents, imaging, and generated artifacts. Keeping
 * the same patient-first relative hierarchy on every disk makes a verbatim mirror
 * auditable without encoding mutable clinical metadata in an object's location.
 * Callers supply the UUID so retries and migration tooling can deterministically
 * address the same destination instead of minting a second object.
 */
final class PhrStorageKey
{
    public static function document(int $patientId, string $objectUuid, string $originalFilename): string
    {
        return sprintf(
            'patients/%d/documents/%s/%s',
            self::patientId($patientId),
            self::uuid($objectUuid),
            self::safeFilename($originalFilename, 'document'),
        );
    }

    public static function dicomUpload(int $patientId, string $uploadUuid): string
    {
        return sprintf(
            'patients/%d/imaging/dicom/uploads/%s',
            self::patientId($patientId),
            self::uuid($uploadUuid),
        );
    }

    /**
     * Append an already-sanitized DICOM path to either a canonical or legacy
     * upload prefix. Accepting both prefix shapes lets an upload opened before a
     * deployment finish safely after new writes switch to canonical keys.
     */
    public static function dicomObject(string $uploadPrefix, string $relativePath): string
    {
        $prefix = trim($uploadPrefix, '/');
        if ($prefix === '') {
            throw new InvalidArgumentException('A DICOM upload prefix is required.');
        }

        $path = trim(str_replace('\\', '/', $relativePath), '/');
        $segments = explode('/', $path);
        if ($path === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('The DICOM object path must be a safe relative path.');
        }

        return $prefix.'/'.$path;
    }

    public static function dicomDerivedSeries(int $patientId, int $seriesId, int $pipelineVersion): string
    {
        if ($seriesId < 1 || $pipelineVersion < 1) {
            throw new InvalidArgumentException('Series and pipeline identifiers must be positive integers.');
        }

        return sprintf(
            'patients/%d/imaging/dicom/derived/series/%d/v%d.bin.gz',
            self::patientId($patientId),
            $seriesId,
            $pipelineVersion,
        );
    }

    public static function export(int $patientId, string $exportUuid, string $filename): string
    {
        return sprintf(
            'patients/%d/exports/%s/%s',
            self::patientId($patientId),
            self::uuid($exportUuid),
            self::safeFilename($filename, 'export'),
        );
    }

    public static function nativeBackup(int $patientId, string $exportUuid): string
    {
        // Native archives are generated exports on the same private disk. Their
        // stable format name is metadata; the unique directory is the identity.
        return self::export($patientId, $exportUuid, 'phr-native-v1.zip');
    }

    public static function safeFilename(string $filename, string $fallback = 'artifact'): string
    {
        $basename = basename(str_replace('\\', '/', str_replace("\0", '', $filename)));
        $safe = Str::of($basename)
            ->replaceMatches('/[^\pL\pN._-]+/u', '_')
            ->trim('._-')
            ->toString();

        // Production uses local filesystems as well as object stores. Limit bytes,
        // not Unicode code points, so one path component remains below common
        // filesystem limits even when every character is multi-byte.
        $safe = rtrim(mb_strcut($safe, 0, 180, 'UTF-8'), '._-');

        if ($safe !== '') {
            return $safe;
        }

        $safeFallback = Str::of($fallback)
            ->replaceMatches('/[^A-Za-z0-9_-]+/', '_')
            ->trim('_-')
            ->limit(80, '')
            ->toString();

        return $safeFallback !== '' ? $safeFallback : 'artifact';
    }

    private static function patientId(int $patientId): int
    {
        if ($patientId < 1) {
            throw new InvalidArgumentException('A positive patient identifier is required.');
        }

        return $patientId;
    }

    private static function uuid(string $uuid): string
    {
        $normalized = strtolower(trim($uuid));
        if (! Str::isUuid($normalized)) {
            throw new InvalidArgumentException('A valid object UUID is required.');
        }

        return $normalized;
    }
}
