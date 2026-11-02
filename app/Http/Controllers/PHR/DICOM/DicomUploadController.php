<?php

namespace App\Http\Controllers\PHR\DICOM;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DICOM\OpenDicomUploadRequest;
use App\Http\Requests\PHR\DICOM\StoreDicomUploadFileRequest;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DICOM\DicomUploadLimits;
use App\Services\PHR\DICOM\DicomUploadPresenter;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DicomUploadController extends Controller
{
    public function __construct(
        private readonly DicomUploadProcessor $uploadProcessor,
        private readonly PhrPatientAccessService $accessService,
        private readonly DicomUploadPresenter $uploadPresenter,
    ) {}

    /**
     * Open a new per-file upload session. Returns the session row so the
     * client can stream individual files to /uploads/{upload}/files.
     */
    public function open(OpenDicomUploadRequest $request, int $patient): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $rootName = $request->string('root_name')->trim()->value() ?: null;

        $upload = $this->uploadProcessor->openUpload($patientModel, (int) $request->user()?->id, $rootName);

        return response()->json([
            'upload' => $this->uploadPresenter->payload($upload),
            'limits' => [
                ...$this->uploadPresenter->limitsPayload(),
                'max_file_bytes' => $this->uploadProcessor->maxFileBytes(),
                'max_file_size_label' => DicomUploadLimits::formatBytes($this->uploadProcessor->maxFileBytes()),
            ],
            'transport' => $this->uploadProcessor->transport(),
        ], 201);
    }

    /**
     * Process a single uploaded file against an open session.
     */
    public function storeFile(StoreDicomUploadFileRequest $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);

        if ($session->status !== PhrDicomUpload::STATUS_PENDING) {
            throw new HttpException(409, 'Upload session is no longer accepting files.');
        }

        $file = $request->file('file');
        $relativePath = $request->string('relative_path')->trim()->value() ?: null;

        $result = $this->uploadProcessor->processSingleFile($session, $file, $relativePath);

        return response()->json([
            'result' => $result,
            'upload' => $this->uploadPresenter->payload($session->refresh()),
        ]);
    }

    /**
     * Sign a PUT for one file of a direct upload, or say it won't be stored. The URL is a
     * credential: never log it.
     */
    public function authorizeFile(Request $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);
        abort_unless($this->uploadProcessor->transport() === DicomUploadProcessor::TRANSPORT_S3, 409, 'This installation receives files through the application.');
        $validated = $request->validate([
            'relative_path' => ['nullable', 'string', 'max:1024'],
            'filename' => ['required', 'string', 'max:255'],
            'byte_size' => ['required', 'integer', 'min:0', 'max:'.$this->uploadProcessor->maxFileBytes()],
            'content_type' => ['nullable', 'string', 'max:255'],
        ]);

        $authorized = $this->uploadProcessor->authorizeFile(
            $session,
            $validated['relative_path'] ?? null,
            $validated['filename'],
            (int) $validated['byte_size'],
            $validated['content_type'] ?? null,
        );
        if (isset($authorized['skipped'])) {
            return $this->fileResponse($authorized['skipped'], $session);
        }

        return response()->json(['authorization' => $authorized])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Record a file the browser sent straight to the store, answering as storeFile does.
     */
    public function confirmFile(Request $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);
        $validated = $request->validate(['token' => ['required', 'string']]);

        return $this->fileResponse($this->uploadProcessor->confirmFile($session, $validated['token']), $session);
    }

    /** @param array{stored: bool, skipped_reason: string|null, relative_path: string, study_id: int|null} $result */
    private function fileResponse(array $result, PhrDicomUpload $session): JsonResponse
    {
        return response()->json([
            'result' => $result,
            'upload' => $this->uploadPresenter->payload($session->refresh()),
        ]);
    }

    /**
     * Finalize an open session.
     */
    public function finalize(Request $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);

        if ($session->status === PhrDicomUpload::STATUS_PENDING) {
            $session = $this->uploadProcessor->finalizeUpload($session);
        }

        return response()->json([
            'upload' => $this->uploadPresenter->payload($session),
            'duplicate_upload' => $this->uploadProcessor->isDuplicateUploadDiscard($session),
        ]);
    }

    /**
     * Cancel an open session by marking it failed and reclaiming its storage.
     */
    public function cancel(Request $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);

        if ($session->status === PhrDicomUpload::STATUS_PENDING) {
            $this->uploadProcessor->failUpload($session, 'Upload cancelled by user.');
            $session->refresh();
        }

        return response()->json(['upload' => $this->uploadPresenter->payload($session)]);
    }

    private function resolvePatient(Request $request, int $patient): PhrPatient
    {
        $userId = (int) $request->user()?->id;

        return $this->accessService->writablePatient($patient, $userId);
    }

    private function resolveSession(PhrPatient $patient, int $uploadId): PhrDicomUpload
    {
        return PhrDicomUpload::query()
            ->where('patient_id', $patient->id)
            ->findOrFail($uploadId);
    }
}
