<?php

namespace App\Http\Requests\AgentApi;

use App\Support\AgentApi\EobWriteRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A claim and its lines, with the document it was read from. The server owns
 * the review status (always pending) and the claim fingerprint.
 */
final class UpsertEobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\A[^\p{C}]+\z/u'],
            'source_document_id' => ['required', 'integer', 'min:1'],
            'review_status' => ['prohibited'],
            'expected_version' => ['sometimes', 'nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            ...EobWriteRules::data(false),
            'data.lines.*.fill' => ['sometimes', 'nullable', 'array:'.implode(',', EobWriteRules::fillFields())],
            ...$this->fillFieldRules(),
        ];
    }

    /** @return array<string, mixed> */
    private function fillFieldRules(): array
    {
        $rules = [];
        foreach (EobWriteRules::fillRules() as $field => $fieldRules) {
            // A line with no fill has nothing to require.
            $rules["data.lines.*.fill.{$field}"] = array_map(
                static fn (mixed $rule): mixed => $rule === 'required' ? 'required_with:data.lines.*.fill' : $rule,
                $fieldRules,
            );
        }

        return $rules;
    }

    /** @return array{source_document_id: int, external_id: ?string, expected_version: ?string, data: array<string, mixed>} */
    public function claim(): array
    {
        $validated = $this->validated();

        return [
            'source_document_id' => (int) $validated['source_document_id'],
            'external_id' => isset($validated['external_id']) ? (string) $validated['external_id'] : null,
            'expected_version' => isset($validated['expected_version']) ? (string) $validated['expected_version'] : null,
            'data' => (array) $validated['data'],
        ];
    }
}
