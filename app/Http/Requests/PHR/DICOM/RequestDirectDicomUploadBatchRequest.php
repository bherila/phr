<?php

namespace App\Http\Requests\PHR\DICOM;

use App\Services\PHR\DICOM\DicomUploadLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestDirectDicomUploadBatchRequest extends FormRequest
{
    private const MAX_BATCH_FILES = 50;

    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_FILES],
            'files.*.client_id' => ['required', 'string', 'max:128', 'distinct'],
            'files.*.filename' => ['required', 'string', 'max:255'],
            'files.*.relative_path' => ['nullable', 'string', 'max:1024'],
            'files.*.content_type' => ['nullable', 'string', 'max:128'],
            'files.*.file_size' => ['required', 'integer', 'min:1', 'max:'.DicomUploadLimits::maxDirectFileBytes()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.max' => 'A DICOM upload URL batch may include at most '.self::MAX_BATCH_FILES.' files.',
            'files.*.file_size.max' => 'Each DICOM file must be '.DicomUploadLimits::formatBytes(DicomUploadLimits::maxDirectFileBytes()).' or smaller.',
        ];
    }

    /**
     * @return list<array{client_id: string, filename: string, relative_path?: string|null, content_type?: string|null, file_size: int}>
     */
    public function uploadFiles(): array
    {
        /** @var list<array{client_id: string, filename: string, relative_path?: string|null, content_type?: string|null, file_size: int}> $files */
        $files = $this->validated('files');

        return $files;
    }
}
