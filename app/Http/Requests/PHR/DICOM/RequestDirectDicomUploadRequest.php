<?php

namespace App\Http\Requests\PHR\DICOM;

use App\Services\PHR\DICOM\DicomUploadLimits;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestDirectDicomUploadRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:255'],
            'relative_path' => ['nullable', 'string', 'max:1024'],
            'content_type' => ['nullable', 'string', 'max:128'],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.DicomUploadLimits::maxDirectFileBytes()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file_size.max' => 'Each DICOM file must be '.DicomUploadLimits::formatBytes(DicomUploadLimits::maxDirectFileBytes()).' or smaller.',
        ];
    }
}
