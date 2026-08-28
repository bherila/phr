<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Services\AgentApi\AgentDicomUploadPresenter;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DICOM\DicomUploadLimits;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Reuses the browser's per-file DICOM processor under OAuth scopes. */
final class AgentDicomUploadController extends Controller
{
    public function __construct(
        private readonly DicomUploadProcessor $uploads,
        private readonly PhrPatientAccessService $accessService,
        private readonly AgentDicomUploadPresenter $presenter,
    ) {}

    public function open(Request $request, int $patient): JsonResponse
    {
        $validated = $request->validate(['root_name' => ['nullable', 'string', 'max:255']]);
        $resolvedPatient = $this->writablePatient($request, $patient);
        $upload = $this->uploads->openUpload(
            $resolvedPatient,
            (int) $request->user('api')?->id,
            isset($validated['root_name']) && trim((string) $validated['root_name']) !== '' ? trim((string) $validated['root_name']) : null,
        );

        return response()->json([
            'resource_type' => 'dicom_upload',
            'patient_id' => (int) $resolvedPatient->id,
            'data' => $this->presenter->payload($upload),
            'limits' => $this->presenter->limitsPayload(),
        ], 201);
    }

    public function storeFile(Request $request, int $patient, int $upload): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.DicomUploadLimits::maxMultipartFileKilobytes()],
            'relative_path' => ['nullable', 'string', 'max:1024'],
        ]);
        $resolvedPatient = $this->writablePatient($request, $patient);
        $session = $this->session($resolvedPatient, $upload);
        if ($session->status !== PhrDicomUpload::STATUS_PENDING) {
            throw new HttpException(409, 'Upload session is no longer accepting files.');
        }
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422, 'Attach the DICOM file to upload.');
        $result = $this->uploads->processSingleFile($session, $file, $validated['relative_path'] ?? null);
        // The browser returns the relative path so an interactive file picker
        // can show progress. A source path can identify a person, so agents
        // receive only the processing outcome and resulting study handle.
        $result = [
            'stored' => $result['stored'],
            'skipped_reason' => $result['skipped_reason'],
            'study_id' => $result['study_id'],
        ];

        return response()->json([
            'resource_type' => 'dicom_upload_file',
            'patient_id' => (int) $resolvedPatient->id,
            'upload_id' => (int) $session->id,
            'result' => $result,
            'data' => $this->presenter->payload($session->refresh()),
        ]);
    }

    public function finalize(Request $request, int $patient, int $upload): JsonResponse
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $session = $this->session($resolvedPatient, $upload);
        if ($session->status === PhrDicomUpload::STATUS_PENDING) {
            $session = $this->uploads->finalizeUpload($session);
        }

        return response()->json([
            'resource_type' => 'dicom_upload',
            'patient_id' => (int) $resolvedPatient->id,
            'duplicate_upload' => $this->uploads->isDuplicateUploadDiscard($session),
            'data' => $this->presenter->payload($session),
        ]);
    }

    public function cancel(Request $request, int $patient, int $upload): JsonResponse
    {
        $resolvedPatient = $this->writablePatient($request, $patient);
        $session = $this->session($resolvedPatient, $upload);
        if ($session->status === PhrDicomUpload::STATUS_PENDING) {
            $this->uploads->failUpload($session, 'Upload cancelled by user.');
            $session->refresh();
        }

        return response()->json([
            'resource_type' => 'dicom_upload',
            'patient_id' => (int) $resolvedPatient->id,
            'data' => $this->presenter->payload($session),
        ]);
    }

    private function writablePatient(Request $request, int $patient): PhrPatient
    {
        return $this->accessService->writablePatient($patient, (int) $request->user('api')?->id);
    }

    private function session(PhrPatient $patient, int $upload): PhrDicomUpload
    {
        return PhrDicomUpload::query()->where('patient_id', $patient->id)->findOrFail($upload);
    }
}
