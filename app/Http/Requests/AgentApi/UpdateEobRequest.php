<?php

namespace App\Http\Requests\AgentApi;

use App\Support\AgentApi\EobWriteRules;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateEobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'source_document_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'review_status' => ['prohibited'],
            ...EobWriteRules::data(true),
        ];
    }
}
