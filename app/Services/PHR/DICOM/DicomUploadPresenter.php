<?php

namespace App\Services\PHR\DICOM;

use App\Models\PhrDicomUpload;

/** Shared upload-session representation; it never exposes a storage prefix. */
final class DicomUploadPresenter
{
    /** @return array<string, mixed> */
    public function payload(PhrDicomUpload $upload): array
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

    /** @return array{max_file_bytes: int, max_file_size_label: string, direct_upload: bool} */
    public function limitsPayload(): array
    {
        $maxFileBytes = DicomUploadLimits::maxMultipartFileBytes();

        return [
            'max_file_bytes' => $maxFileBytes,
            'max_file_size_label' => DicomUploadLimits::formatBytes($maxFileBytes),
            'direct_upload' => true,
        ];
    }
}
