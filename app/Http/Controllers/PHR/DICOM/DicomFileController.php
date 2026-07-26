<?php

namespace App\Http\Controllers\PHR\DICOM;

use App\Http\Controllers\Controller;
use App\Models\PhrDicomFile;
use App\Models\PhrDicomInstance;
use App\Models\PhrDicomStudy;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

class DicomFileController extends Controller
{
    public function __construct(
        private readonly DicomUploadProcessor $uploadProcessor,
        private readonly PhrPatientAccessService $accessService,
    ) {}

    public function proxyInstanceFile(Request $request, int $patient, int $instance): RedirectResponse|StreamedResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $resolvedInstance = PhrDicomInstance::query()
            ->where('patient_id', $resolvedPatient->id)
            ->with(['file'])
            ->findOrFail($instance);

        if ($this->uploadProcessor->shouldUseDirectSignedViewerUrls()) {
            return redirect()
                ->away($this->uploadProcessor->temporaryViewerUrl($resolvedInstance->file))
                ->header('Cache-Control', 'private, no-store');
        }

        $stream = $this->uploadProcessor->disk()->readStream($resolvedInstance->file->r2_key);
        abort_if(! is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/dicom',
            'Content-Length' => (string) $resolvedInstance->file->file_size_bytes,
            'Content-Disposition' => 'inline; filename="'.$this->safeDownloadName($resolvedInstance->file->original_filename).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadStudy(Request $request, int $patient, int $study): StreamedResponse
    {
        $userId = (int) $request->user()?->id;
        $resolvedPatient = $this->accessService->accessiblePatient($patient, $userId);
        $resolvedStudy = PhrDicomStudy::query()
            ->forPatient((int) $resolvedPatient->id)
            ->with(['instances'])
            ->findOrFail($study);

        $files = $this->studyFiles($resolvedStudy);
        abort_if($files->isEmpty(), 404);

        $filename = $this->zipFilename($resolvedStudy);

        return response()->stream(function () use ($files, $filename): void {
            $disk = $this->uploadProcessor->disk();
            $zip = new ZipStream(outputName: $filename, sendHttpHeaders: false);

            foreach ($files as $file) {
                $stream = $disk->readStream($file->r2_key);
                if (! is_resource($stream)) {
                    continue;
                }

                try {
                    $zip->addFileFromStream($this->zipPath($file->original_relative_path), $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }

            $zip->finish();
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$this->safeDownloadName($filename).'"',
            'Cache-Control' => 'private, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return Collection<int, PhrDicomFile>
     */
    private function studyFiles(PhrDicomStudy $study): Collection
    {
        $instanceFileIds = $study->instances->pluck('file_id')->filter()->unique()->values()->all();
        $uploadIds = $study->instances->pluck('upload_id')->filter()->unique()->values()->all();

        return PhrDicomFile::query()
            ->where('patient_id', $study->patient_id)
            ->where(function (Builder $query) use ($instanceFileIds, $uploadIds): void {
                $query->whereIn('id', $instanceFileIds);

                if ($uploadIds !== []) {
                    $query->orWhere(function (Builder $nested) use ($uploadIds): void {
                        $nested
                            ->whereIn('upload_id', $uploadIds)
                            ->where('file_kind', PhrDicomFile::KIND_DICOMDIR);
                    });
                }
            })
            ->orderBy('original_relative_path')
            ->get();
    }

    private function zipPath(string $relativePath): string
    {
        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', $relativePath)),
            fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        ));

        return $segments === [] ? 'dicom-file' : implode('/', $segments);
    }

    private function zipFilename(PhrDicomStudy $study): string
    {
        $label = $study->description ?: $study->study_instance_uid;
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label) ?: 'study';

        return 'phr-dicom-study-'.$study->id.'-'.substr(trim($slug, '-'), 0, 80).'.zip';
    }

    private function safeDownloadName(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], '', $filename);
    }
}
