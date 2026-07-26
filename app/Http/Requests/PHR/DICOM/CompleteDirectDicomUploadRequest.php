<?php

namespace App\Http\Requests\PHR\DICOM;

use App\Services\PHR\DICOM\DicomUploadLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteDirectDicomUploadRequest extends FormRequest
{
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
            'r2_key' => ['required', 'string', 'max:2048'],
            'relative_path' => ['required', 'string', 'max:1024'],
            'original_filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:128'],
            'file_size_bytes' => ['required', 'integer', 'min:1', 'max:'.DicomUploadLimits::maxDirectFileBytes()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file_size_bytes.max' => 'Each DICOM file must be '.DicomUploadLimits::formatBytes(DicomUploadLimits::maxDirectFileBytes()).' or smaller.',
        ];
    }
}
