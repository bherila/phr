<?php

namespace App\Http\Requests\PHR\DICOM;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDicomUploadFileRequest extends FormRequest
{
    public const int MAX_FILE_KILOBYTES = 204800;

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
            'file' => ['required', 'file', 'max:'.self::MAX_FILE_KILOBYTES],
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
            'file.max' => 'Each DICOM file must be 200 MB or smaller.',
            'file.uploaded' => 'The DICOM file could not be uploaded. It may exceed the server upload limit. Try a smaller file or ask an administrator to raise the PHP upload_max_filesize, post_max_size, and web server body size limits.',
        ];
    }
}
