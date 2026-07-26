<?php

namespace App\Http\Controllers\PHR\DICOM;

use App\Http\Controllers\Controller;
use App\Http\Requests\PHR\DICOM\CompleteDirectDicomUploadRequest;
use App\Http\Requests\PHR\DICOM\OpenDicomUploadRequest;
use App\Http\Requests\PHR\DICOM\RequestDirectDicomUploadBatchRequest;
use App\Http\Requests\PHR\DICOM\RequestDirectDicomUploadRequest;
use App\Http\Requests\PHR\DICOM\StoreDicomUploadFileRequest;
use App\Models\PhrDicomUpload;
use App\Models\PhrPatient;
use App\Services\PHR\Access\PhrPatientAccessService;
use App\Services\PHR\DICOM\DicomUploadLimits;
use App\Services\PHR\DICOM\DicomUploadProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DicomUploadController extends Controller
{
    public function __construct(
        private readonly DicomUploadProcessor $uploadProcessor,
        private readonly PhrPatientAccessService $accessService,
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
            'upload' => $this->uploadPayload($upload),
            'limits' => $this->uploadLimitsPayload(),
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
            'upload' => $this->uploadPayload($session->refresh()),
        ]);
    }

    /**
     * Reserve an R2 object key and return a signed PUT URL for the browser.
     */
    public function requestUploadUrl(RequestDirectDicomUploadRequest $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);

        if ($session->status !== PhrDicomUpload::STATUS_PENDING) {
            throw new HttpException(409, 'Upload session is no longer accepting files.');
        }

        $signedUpload = $this->uploadProcessor->requestDirectUpload(
            $session,
            $request->string('filename')->value(),
            $request->string('relative_path')->trim()->value() ?: null,
            $request->string('content_type')->trim()->value() ?: null,
            $request->integer('file_size'),
        );

        return response()->json($this->signedUploadPayload($signedUpload));
    }

    /**
     * Reserve R2 object keys and return signed PUT URLs for a client-side batch.
     */
    public function requestUploadUrls(RequestDirectDicomUploadBatchRequest $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);

        if ($session->status !== PhrDicomUpload::STATUS_PENDING) {
            throw new HttpException(409, 'Upload session is no longer accepting files.');
        }

        $signedUploads = $this->uploadProcessor->requestDirectUploadBatch($session, $request->uploadFiles());

        return response()->json([
            'uploads' => array_map(fn (array $signedUpload): array => $this->signedUploadBatchPayload($signedUpload), $signedUploads),
        ]);
    }

    /**
     * Register a browser-uploaded R2 object against the open DICOM session.
     */
    public function completeFile(CompleteDirectDicomUploadRequest $request, int $patient, int $upload): JsonResponse
    {
        $patientModel = $this->resolvePatient($request, $patient);
        $session = $this->resolveSession($patientModel, $upload);

        if ($session->status !== PhrDicomUpload::STATUS_PENDING) {
            throw new HttpException(409, 'Upload session is no longer accepting files.');
        }

        $result = $this->uploadProcessor->processDirectUploadedFile(
            $session,
            $request->string('r2_key')->value(),
            $request->string('relative_path')->value(),
            $request->string('original_filename')->value(),
            $request->string('mime_type')->trim()->value() ?: null,
            $request->integer('file_size_bytes'),
        );

        return response()->json([
            'result' => $result,
            'upload' => $this->uploadPayload($session->refresh()),
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
            'upload' => $this->uploadPayload($session),
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

        return response()->json(['upload' => $this->uploadPayload($session)]);
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

    /**
     * @return array<string, mixed>
     */
    private function uploadPayload(PhrDicomUpload $upload): array
    {
        return [
            'id' => $upload->id,
            'patient_id' => $upload->patient_id,
            'uploaded_by_user_id' => $upload->uploaded_by_user_id,
            'status' => $upload->status,
            'original_root_name' => $upload->original_root_name,
            'total_files' => $upload->total_files,
            'stored_files' => $upload->stored_files,
            'skipped_files' => $upload->skipped_files,
            'total_bytes' => $upload->total_bytes,
            'stored_bytes' => $upload->stored_bytes,
            'manifest_json' => $upload->manifest_json,
            'skipped_files_json' => $upload->skipped_files_json,
            'error_message' => $upload->error_message,
            'created_at' => $upload->created_at?->toDateTimeString(),
            'updated_at' => $upload->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array{max_file_bytes: int, max_file_size_label: string, direct_upload: bool}
     */
    private function uploadLimitsPayload(): array
    {
        $maxFileBytes = DicomUploadLimits::maxDirectFileBytes();

        return [
            'max_file_bytes' => $maxFileBytes,
            'max_file_size_label' => DicomUploadLimits::formatBytes($maxFileBytes),
            'direct_upload' => true,
        ];
    }

    /**
     * @param  array{upload_url: string, headers: array<string, string>, r2_key: string, relative_path: string, expires_in: int}  $signedUpload
     * @return array{upload_url: string, headers: object, r2_key: string, relative_path: string, expires_in: int}
     */
    private function signedUploadPayload(array $signedUpload): array
    {
        return [
            'upload_url' => $signedUpload['upload_url'],
            'headers' => (object) $signedUpload['headers'],
            'r2_key' => $signedUpload['r2_key'],
            'relative_path' => $signedUpload['relative_path'],
            'expires_in' => $signedUpload['expires_in'],
        ];
    }

    /**
     * @param  array{client_id: string, upload_url: string, headers: array<string, string>, r2_key: string, relative_path: string, expires_in: int}  $signedUpload
     * @return array{client_id: string, upload_url: string, headers: object, r2_key: string, relative_path: string, expires_in: int}
     */
    private function signedUploadBatchPayload(array $signedUpload): array
    {
        return [
            'client_id' => $signedUpload['client_id'],
            'upload_url' => $signedUpload['upload_url'],
            'headers' => (object) $signedUpload['headers'],
            'r2_key' => $signedUpload['r2_key'],
            'relative_path' => $signedUpload['relative_path'],
            'expires_in' => $signedUpload['expires_in'],
        ];
    }
}
