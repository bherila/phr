<?php

namespace App\Services\PHR\Export;

use App\Models\PhrDicomFile;
use App\Models\PhrDocument;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class PhrExportArchiveBuilder
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $artifacts
     */
    public function writeZip(string $targetPath, array $data, array $artifacts): void
    {
        $zip = new ZipArchive;
        if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Unable to create ZIP at {$targetPath}");
        }

        $tempFiles = [];
        try {
            foreach ($artifacts as $path => $contents) {
                $zip->addFromString($path, $contents);
            }

            foreach ($data['documents'] as $document) {
                if (! $document instanceof PhrDocument || ! $document->storage_path) {
                    continue;
                }

                $this->addStorageFile(
                    $zip,
                    $document->storage_disk,
                    $document->storage_path,
                    'documents/'.$document->id.'-'.$this->safeFilename($document->original_filename ?? ('document-'.$document->id)),
                    $tempFiles
                );
            }

            foreach ($data['dicom_files'] as $file) {
                if (! $file instanceof PhrDicomFile) {
                    continue;
                }

                $studyUid = $file->instance?->study->study_instance_uid
                    ?? ($file->metadata_json['study_instance_uid'] ?? null)
                    ?? 'unknown-study';

                $this->addStorageFile(
                    $zip,
                    'phr_dicom',
                    $file->r2_key,
                    'dicom/studies/'.$this->safePath((string) $studyUid).'/'.$this->safePath($file->original_relative_path),
                    $tempFiles
                );
            }
        } finally {
            $zip->close();
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * @param  array<int, string>  $tempFiles
     */
    private function addStorageFile(ZipArchive $zip, string $disk, string $storagePath, string $zipPath, array &$tempFiles): void
    {
        if (! Storage::disk($disk)->exists($storagePath)) {
            return;
        }

        $readStream = Storage::disk($disk)->readStream($storagePath);
        if (! is_resource($readStream)) {
            throw new \RuntimeException("Unable to read {$storagePath} from {$disk}.");
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'phr-zip-file-');
        if ($tempPath === false) {
            fclose($readStream);

            throw new \RuntimeException('Unable to create temporary ZIP entry file.');
        }

        $writeStream = fopen($tempPath, 'wb');
        if ($writeStream === false) {
            fclose($readStream);
            @unlink($tempPath);

            throw new \RuntimeException('Unable to open temporary ZIP entry file.');
        }

        try {
            $copied = stream_copy_to_stream($readStream, $writeStream);
        } finally {
            fclose($readStream);
            fclose($writeStream);
        }

        if ($copied === false) {
            @unlink($tempPath);

            throw new \RuntimeException("Unable to stream {$storagePath} from {$disk}.");
        }

        if (! $zip->addFile($tempPath, $zipPath)) {
            @unlink($tempPath);

            throw new \RuntimeException("Unable to add {$zipPath} to ZIP.");
        }

        $tempFiles[] = $tempPath;
    }

    private function safeFilename(string $filename): string
    {
        $safe = preg_replace('/[^\w.\-]+/', '_', $filename) ?: 'document';

        return trim($safe, '_') ?: 'document';
    }

    private function safePath(string $path): string
    {
        $segments = array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..');

        return implode('/', array_map(fn (string $segment): string => $this->safeFilename($segment), $segments));
    }
}
