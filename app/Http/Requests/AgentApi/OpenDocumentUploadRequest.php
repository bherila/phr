<?php

namespace App\Http\Requests\AgentApi;

use App\Models\PhrDocumentUpload;
use App\Support\PHR\PhrDocumentUploadLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** What a signed upload may declare: the same allowlist and size limit as documents.upload. */
final class OpenDocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(PhrDocumentUpload::KINDS)],
            'filename' => ['required', 'string', 'min:1', 'max:255', 'regex:/\A[^\p{C}\/\\\\]+\z/u'],
            'content_type' => ['required', 'string', Rule::in(array_keys(PhrDocumentUploadLimits::CONTENT_TYPES))],
            'byte_size' => ['required', 'integer', 'min:1', 'max:'.PhrDocumentUploadLimits::MAX_BYTES],
            'sha256' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-fA-F0-9]{64}\z/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty()
                && ! PhrDocumentUploadLimits::allows((string) $this->input('content_type'), (string) $this->input('filename'))) {
                $validator->errors()->add('filename', 'The filename extension does not match the content type.');
            }
        });
    }

    /** @return array{kind: string, filename: string, content_type: string, byte_size: int, sha256: string|null} */
    public function declared(): array
    {
        $validated = $this->validated();

        return [
            'kind' => (string) $validated['kind'],
            'filename' => (string) $validated['filename'],
            'content_type' => (string) $validated['content_type'],
            'byte_size' => (int) $validated['byte_size'],
            'sha256' => isset($validated['sha256']) ? (string) $validated['sha256'] : null,
        ];
    }
}
