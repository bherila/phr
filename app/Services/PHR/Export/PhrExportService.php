<?php

namespace App\Services\PHR\Export;

use App\Jobs\PHR\GeneratePhrExportJob;
use App\Models\PhrExport;
use App\Models\PhrPatient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PhrExportService
{
    public const array FORMATS = ['fhir', 'ccda', 'pdf', 'zip'];

    public function __construct(
        private PhrExportDataService $dataService,
        private PhrFhirExporter $fhirExporter,
        private PhrCcdaExporter $ccdaExporter,
        private PhrPdfSummaryRenderer $pdfSummaryRenderer,
        private PhrExportArchiveBuilder $archiveBuilder,
    ) {}

    /**
     * @param  array<int, string>  $formats
     */
    public function createQueuedExport(PhrPatient $patient, int $requestedByUserId, array $formats): PhrExport
    {
        $formats = $this->normalizeFormats($formats);
        $export = PhrExport::create([
            'patient_id' => $patient->id,
            'user_id' => $patient->owner_user_id,
            'requested_by_user_id' => $requestedByUserId,
            'format' => $this->singleOutputFormat($formats),
            'formats_json' => $formats,
            'status' => PhrExport::STATUS_PENDING,
            'storage_disk' => 'phr_exports',
            'expires_at' => now()->addDays((int) config('phr.exports_retention_days', 30)),
        ]);

        GeneratePhrExportJob::dispatch($export->id);

        return $export;
    }

    public function generate(PhrExport $export): PhrExport
    {
        $export->update(['status' => PhrExport::STATUS_PROCESSING, 'error_message' => null]);

        $tempPath = null;
        try {
            $patient = PhrPatient::query()->findOrFail($export->patient_id);
            $formats = $this->normalizeFormats($export->formats_json ?: [$export->format]);
            [$filename, $tempPath] = $this->buildExportFile($patient, $formats);
            $storagePath = 'phr/exports/patients/'.$patient->id.'/'.Str::uuid().'/'.$filename;

            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to read generated export at {$tempPath}");
            }

            try {
                $stored = Storage::disk('phr_exports')->put($storagePath, $stream);
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw new \RuntimeException('Unable to store generated PHR export.');
            }

            $fileSize = filesize($tempPath);

            $export->update([
                'format' => $this->singleOutputFormat($formats),
                'filename' => $filename,
                'storage_path' => $storagePath,
                'file_size_bytes' => $fileSize === false ? null : $fileSize,
                'status' => PhrExport::STATUS_READY,
                'generated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $export->update([
                'status' => PhrExport::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        return $export->refresh();
    }

    /**
     * @param  array<int, string>  $formats
     */
    public function generateToPath(PhrPatient $patient, array $formats, string $outPath): string
    {
        $formats = $this->normalizeFormats($formats);
        [$filename, $tempPath] = $this->buildExportFile($patient, $formats);

        try {
            $target = is_dir($outPath) ? rtrim($outPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename : $outPath;

            if (! copy($tempPath, $target)) {
                throw new \RuntimeException("Unable to write export to {$target}");
            }

            return $target;
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * @param  array<int, string>  $formats
     * @return array{string, string}
     */
    private function buildExportFile(PhrPatient $patient, array $formats): array
    {
        $data = $this->dataService->load($patient);
        $date = now()->format('Ymd');
        $base = 'patient-'.$patient->id.'-'.$date;

        $artifacts = [];
        if (in_array('fhir', $formats, true) || in_array('zip', $formats, true)) {
            $artifacts['fhir.json'] = $this->fhirExporter->bundleJson($data);
        }
        if (in_array('ccda', $formats, true) || in_array('zip', $formats, true)) {
            $artifacts['ccda.xml'] = $this->ccdaExporter->documentXml($data);
        }
        if (in_array('pdf', $formats, true) || in_array('zip', $formats, true)) {
            $artifacts['summary.pdf'] = $this->pdfSummaryRenderer->render($data);
        }

        if (in_array('zip', $formats, true) || count($formats) > 1) {
            $temp = tempnam(sys_get_temp_dir(), 'phr-export-');
            if ($temp === false) {
                throw new \RuntimeException('Unable to create temporary export file.');
            }

            $this->archiveBuilder->writeZip($temp, $data, $artifacts);

            return [$base.'.zip', $temp];
        }

        $format = $formats[0];
        $path = match ($format) {
            'fhir' => $base.'-fhir.json',
            'ccda' => $base.'-ccda.xml',
            'pdf' => $base.'-summary.pdf',
            default => throw new InvalidArgumentException("Unsupported export format: {$format}"),
        };

        $temp = tempnam(sys_get_temp_dir(), 'phr-export-');
        if ($temp === false) {
            throw new \RuntimeException('Unable to create temporary export file.');
        }

        if (file_put_contents($temp, array_values($artifacts)[0] ?? '') === false) {
            @unlink($temp);

            throw new \RuntimeException('Unable to write generated export file.');
        }

        return [$path, $temp];
    }

    /**
     * @param  array<int, string>  $formats
     * @return array<int, string>
     */
    public function normalizeFormats(array $formats): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $format): string => strtolower(trim((string) $format)),
            $formats
        )));
        $normalized = array_values(array_filter($normalized, static fn (string $format): bool => $format !== ''));

        if ($normalized === []) {
            $normalized = ['zip'];
        }

        $invalid = array_diff($normalized, self::FORMATS);
        if ($invalid !== []) {
            throw new InvalidArgumentException('Unsupported PHR export format: '.implode(', ', $invalid));
        }

        if (in_array('zip', $normalized, true)) {
            return ['zip'];
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $formats
     */
    private function singleOutputFormat(array $formats): string
    {
        return count($formats) === 1 ? $formats[0] : 'zip';
    }
}
