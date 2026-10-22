<?php

namespace App\Services\PHR\NativeBackup;

/** Shared writer/reader admission policy. Counts and bytes are independent bounds. */
final class NativeResourceBudget
{
    public const int MANIFEST_BYTES = 1024 * 1024;

    private int $records = 0;

    private int $bytes = 0;

    private int $metadataBytes = 0;

    private int $artifacts = 0;

    public function __construct(private readonly bool $restoring = false) {}

    public static function maxRecords(): int
    {
        return max(1, (int) config('phr.native_max_records'));
    }

    public static function maxRecordStreamBytes(): int
    {
        return max(1, (int) config('phr.native_max_record_stream_bytes'));
    }

    public function charge(int $bytes, bool $isRecord = true): void
    {
        if ($bytes > (int) config('phr.native_restore_max_record_bytes')) {
            $this->refuse('record_size_limit');
        }
        if ($bytes > self::maxRecordStreamBytes() - $this->bytes) {
            $this->refuse('record_stream_size_limit');
        }
        if ($isRecord && $this->records >= self::maxRecords()) {
            $this->refuse('record_count_limit');
        }
        $this->bytes += $bytes;
        $this->records += $isRecord ? 1 : 0;
    }

    public function preflight(int $records, int $bytes): void
    {
        if ($records > self::maxRecords()) {
            $this->refuse('record_count_limit');
        }
        if ($bytes > self::maxRecordStreamBytes()) {
            $this->refuse('record_stream_size_limit');
        }
    }

    /** Metadata is charged before retaining artifact candidates or validating entries. */
    public function artifact(int $bytes): void
    {
        if ($this->artifacts >= (int) config('phr.native_max_artifacts')
            || $bytes > self::MANIFEST_BYTES - $this->metadataBytes) {
            $this->refuse('artifact_metadata_limit');
        }
        $this->artifacts++;
        $this->metadataBytes += $bytes;
    }

    public function manifest(int $bytes): void
    {
        if ($bytes >= self::MANIFEST_BYTES) {
            $this->refuse('manifest_size_limit');
        }
    }

    private function refuse(string $reason): never
    {
        throw $this->restoring ? new NativeRestoreException($reason) : new NativeBackupException($reason);
    }
}
