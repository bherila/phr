<?php

namespace App\Http\Requests\PHR;

use App\Models\PhrDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhrDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,png,jpg,jpeg,gif,webp,tif,tiff,txt,html,htm'],
            'title' => ['nullable', 'string', 'max:255'],
            'document_type' => ['required', 'string', Rule::in(PhrDocument::DOCUMENT_TYPES)],
            'observed_at' => ['nullable', 'date'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:50', 'distinct:ignore_case'],
        ];
    }
}
