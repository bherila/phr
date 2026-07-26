<?php

namespace App\Http\Requests\PHR;

use App\Models\PhrDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePhrDocumentRequest extends FormRequest
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
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'document_type' => ['sometimes', 'required', 'string', Rule::in(PhrDocument::DOCUMENT_TYPES)],
            'observed_at' => ['sometimes', 'nullable', 'date'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:50', 'distinct:ignore_case'],
        ];
    }
}
