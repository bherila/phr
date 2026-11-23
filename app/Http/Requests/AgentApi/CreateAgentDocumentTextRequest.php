<?php

namespace App\Http\Requests\AgentApi;

use App\DataTransferObjects\PHR\DocumentTextData;
use App\Models\PhrDocument;
use App\Support\PHR\Validation\DocumentNoteRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * documents.create_text (phr#409): a markdown note and its metadata, under the
 * agent's external_id.
 */
final class CreateAgentDocumentTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            'body' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        $fail('The body must hold the note\'s markdown text.');
                    } elseif (strlen($value) > DocumentTextData::MAX_BODY_BYTES) {
                        $fail('The body may be at most '.DocumentTextData::MAX_BODY_BYTES.' bytes.');
                    } elseif (! mb_check_encoding($value, 'UTF-8')) {
                        $fail('The body must be UTF-8 text.');
                    }
                },
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'document_type' => ['required', 'string', Rule::in(PhrDocument::DOCUMENT_TYPES)],
            'observed_at' => ['nullable', 'date'],
            'summary' => ['nullable', 'string', 'max:20000'],
            'tags' => ['nullable', 'array', 'max:30'],
            'tags.*' => ['string', 'max:50', 'distinct:ignore_case'],
            ...DocumentNoteRules::rules(),
        ];
    }
}
