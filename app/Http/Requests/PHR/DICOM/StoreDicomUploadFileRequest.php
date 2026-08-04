<?php

namespace App\Http\Requests\PHR\DICOM;

use App\Services\PHR\DICOM\DicomUploadLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDicomUploadFileRequest extends FormRequest
{
    public const int MAX_FILE_KILOBYTES = DicomUploadLimits::MAX_MULTIPART_FILE_KILOBYTES;

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
            'file' => ['required', 'file', 'max:'.$this->maxFileKilobytes()],
            'relative_path' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Attach the DICOM file to upload.',
            'file.max' => 'Each DICOM file must be '.DicomUploadLimits::formatBytes(DicomUploadLimits::maxMultipartFileBytes()).' or smaller.',
            'file.uploaded' => 'The DICOM file could not be uploaded. It may exceed the server upload limit. Try a smaller file or ask an administrator to raise the PHP upload_max_filesize, post_max_size, and web server body size limits.',
        ];
    }

    private function maxFileKilobytes(): int
    {
        return intdiv(DicomUploadLimits::maxMultipartFileBytes(), 1024);
    }
}
