<?php

namespace App\Http\Controllers\PHR\DICOM;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DICOM\OpenDicomUploadRequest;
use App\Http\Requests\PHR\DICOM\StoreDicomUploadFileRequest;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
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
            'limits' => $this->uploadPresenter->limitsPayload(),
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
