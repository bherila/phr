<?php

namespace App\Services\PHR\DICOM;

use App\Models\PhrDicomFile;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomSeries;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use App\Support\Storage\PhrStorageKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class VolumeCacheService
{
    public function __construct(
        private readonly DicomUploadProcessor $uploadProcessor,
        private readonly PhrPatientArtifactWriteGuard $artifactWriteGuard,
    ) {}

    public function pipelineVersion(): int
    {
        return (int) config('phr.volume_cache_pipeline_version', 1);
    }

    public function artifact(PhrDicomSeries $series): ?PhrDicomFile
    {
        $canonicalKey = $this->storageKey($series, $this->pipelineVersion());

        return PhrDicomFile::query()
            ->where('patient_id', $series->patient_id)
            ->where('file_kind', PhrDicomFile::KIND_DERIVED_VOLUME)
            ->whereIn('r2_key', [
                $canonicalKey,
                $this->legacyStorageKey($series, $this->pipelineVersion()),
            ])
            // Prefer the canonical row if a partially completed rollout left both.
            ->orderByRaw('CASE WHEN r2_key = ? THEN 0 ELSE 1 END', [$canonicalKey])
            ->first();
    }

    public function store(PhrDicomSeries $series, UploadedFile $artifact): PhrDicomFile
    {
        return $this->artifactWriteGuard->run((int) $series->patient_id, function () use ($series, $artifact): PhrDicomFile {
            $currentSeries = PhrDicomSeries::query()
                ->where('patient_id', $series->patient_id)
                ->findOrFail($series->id);

            return $this->storeWhilePatientLocked($currentSeries, $artifact);
        });
    }

    private function storeWhilePatientLocked(PhrDicomSeries $series, UploadedFile $artifact): PhrDicomFile
    {
        $pipelineVersion = $this->pipelineVersion();
        $storageKey = $this->storageKey($series, $pipelineVersion);
        $realPath = $artifact->getPathname();
        $byteSize = $artifact->getSize();
        $sha256 = hash_file('sha256', $realPath);
        if ($byteSize === false || $sha256 === false) {
            throw new RuntimeException('Unable to read the volume cache artifact.');
        }

        $stream = fopen($realPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the volume cache artifact.');
        }

        try {
            if (! $this->disk()->put($storageKey, $stream)) {
                throw new RuntimeException('Unable to store the volume cache artifact.');
            }
        } finally {
            fclose($stream);
        }

        $firstInstance = PhrDicomInstance::query()
            ->where('series_id', $series->id)
            ->orderBy('id')
            ->firstOrFail();
        $originalPathHash = hash('sha256', $storageKey);

        $values = [
            'patient_id' => $series->patient_id,
            'upload_id' => $firstInstance->upload_id,
            'file_kind' => PhrDicomFile::KIND_DERIVED_VOLUME,
            'r2_key' => $storageKey,
            'original_relative_path' => $storageKey,
            'original_filename' => "volume-cache-v{$pipelineVersion}.bin.gz",
            'mime_type' => 'application/gzip',
            'file_size_bytes' => $byteSize,
            'sha256' => $sha256,
            'metadata_json' => [
                'kind' => 'volume_cache',
                'series_id' => $series->id,
                'pipeline_version' => $pipelineVersion,
            ],
        ];

        // Re-generating a legacy current-version cache is also a safe lazy
        // migration: the canonical bytes are durable before its existing row is
        // repointed, and the legacy object remains available for rollback.
        $existing = $this->artifact($series);
        if ($existing !== null) {
            $existing->fill([
                ...$values,
                'original_path_hash' => $originalPathHash,
            ])->save();

            return $existing->refresh();
        }

        return PhrDicomFile::query()->create([
            ...$values,
            'original_path_hash' => $originalPathHash,
        ]);
    }

    /**
     * @return array{available: bool, url: string|null, pipeline_version: int}
     */
    public function manifestPayload(PhrDicomSeries $series, int $patientId): array
    {
        $artifact = $this->artifact($series);

        return [
            'available' => $artifact !== null,
            'url' => $artifact === null ? null : $this->downloadUrl($artifact, $patientId, (int) $series->id),
            'pipeline_version' => $this->pipelineVersion(),
        ];
    }

    public function downloadUrl(PhrDicomFile $artifact, int $patientId, int $seriesId): string
    {
        if ($this->usesDirectSignedUrls()) {
            return $this->temporaryUrl($artifact);
        }

        return url("/api/phr/patients/{$patientId}/dicom/series/{$seriesId}/volume-cache");
    }

    public function temporaryUrl(PhrDicomFile $artifact): string
    {
        return $this->disk()->temporaryUrl(
            $artifact->r2_key,
            now()->addMinutes(max(1, (int) config('phr.dicom_viewer_url_ttl_minutes', 30))),
            [
                'ResponseContentType' => 'application/gzip',
                'ResponseContentDisposition' => 'inline; filename="'.$this->safeFilename($artifact->original_filename).'"',
            ],
        );
    }

    public function usesDirectSignedUrls(): bool
    {
        return $this->uploadProcessor->shouldUseDirectSignedViewerUrls();
    }

    public function disk(): Filesystem
    {
        return $this->uploadProcessor->disk();
    }

    /**
     * Namespace the cache object by the series' own primary key, under its
     * patient.
     *
     * The key deliberately contains no DICOM-supplied identifier.
     * `SeriesInstanceUID` is read verbatim out of an uploaded file, so it is
     * attacker-controlled: keying on it let anyone who knew a victim's series
     * UID mint a colliding series under their own patient and overwrite the
     * victim's cached volume. `patient_id` and `id` are database-assigned and
     * cannot collide across tenants.
     */
    private function storageKey(PhrDicomSeries $series, int $pipelineVersion): string
    {
        return PhrStorageKey::dicomDerivedSeries(
            (int) $series->patient_id,
            (int) $series->id,
            $pipelineVersion,
        );
    }

    /**
     * Immediate pre-canonical key shape retained for reads during migration.
     */
    private function legacyStorageKey(PhrDicomSeries $series, int $pipelineVersion): string
    {
        return sprintf(
            'derived/volume-cache/patients/%d/series/%d/v%d.bin.gz',
            (int) $series->patient_id,
            (int) $series->id,
            $pipelineVersion,
        );
    }

    private function safeFilename(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], '', $filename);
    }
}
