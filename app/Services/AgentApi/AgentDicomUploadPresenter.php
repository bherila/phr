<?php

namespace App\Services\AgentApi;

use App\Models\PhrDicomUpload;
use App\Services\PHR\DICOM\DicomUploadPresenter;

/** Removes browser-only uploader identity from the shared DICOM session shape. */
final readonly class AgentDicomUploadPresenter
{
    public function __construct(private DicomUploadPresenter $base) {}

    /** @return array<string, mixed> */
    public function payload(PhrDicomUpload $upload): array
    {
        $payload = $this->base->payload($upload);
        // Upload manifests, skipped-path lists, and processor errors can contain
        // source filenames or parser text. They are useful in the browser's
        // interactive recovery UI but are not safe agent output.
        unset(
            $payload['uploaded_by_user_id'],
            $payload['manifest_json'],
            $payload['skipped_files_json'],
            $payload['error_message'],
        );

        return $payload;
    }

    /** @return array{max_file_bytes: int, max_file_size_label: string, direct_upload: bool} */
    public function limitsPayload(): array
    {
        return $this->base->limitsPayload();
    }
}
