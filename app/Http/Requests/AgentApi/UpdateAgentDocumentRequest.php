<?php

namespace App\Http\Requests\AgentApi;

use App\Http\Requests\PHR\UpdatePhrDocumentRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * documents.update: the browser's metadata edit ({@see UpdatePhrDocumentRequest})
 * under `data`, guarded by the document's current opaque version.
 */
final class UpdateAgentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = (new UpdatePhrDocumentRequest)->rules();
        $dataRules = [];
        foreach ($fields as $field => $rules) {
            $dataRules['data.'.$field] = $rules;
        }
        $keys = array_values(array_unique(array_map(static fn (string $field): string => explode('.', $field, 2)[0], array_keys($fields))));

        return [
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'data' => ['required', 'array:'.implode(',', $keys), 'min:1'],
            ...$dataRules,
        ];
    }

    /** @return array<string, mixed> the fields to change, as validated */
    public function changes(): array
    {
        return (array) $this->validated('data');
    }
}
