<?php

namespace App\Http\Requests\AgentApi;

use App\Http\Requests\PHR\UpdateHealthLogEntryRequest;
use Bherila\McpLaravelBridge\Json as AgentApiJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * health_log_entries.update: the browser's entry edit ({@see UpdateHealthLogEntryRequest})
 * under `data`, guarded by the entry's current version. As on append, tags must be
 * a JSON array and details a JSON object, and details keeps its wire shape.
 */
final class UpdateAgentHealthLogEntryRequest extends FormRequest
{
    private const array FIELDS = ['occurred_at', 'title', 'notes', 'intensity', 'tags', 'details'];

    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'data' => ['required', 'array:'.implode(',', self::FIELDS), 'min:1'],
            'data.occurred_at' => ['sometimes', 'date'],
            'data.title' => ['nullable', 'string', 'max:255'],
            'data.notes' => ['nullable', 'string', 'max:10000'],
            'data.intensity' => ['nullable', 'integer', 'between:0,10'],
            'data.tags' => ['nullable', 'array', 'list', 'max:20'],
            'data.tags.*' => ['string', 'max:50', 'distinct'],
            'data.details' => ['nullable', 'array', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'data.intensity.between' => 'Intensity must be between 0 and 10.',
            'data.tags.max' => 'An entry may have at most 20 tags.',
            'data.tags.*.distinct' => 'Tags must be unique.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $this->rawData();
            if ($data === null) {
                return;
            }
            if (property_exists($data, 'tags') && $data->tags !== null && ! is_array($data->tags)) {
                $validator->errors()->add('data.tags', 'The tags field must be a JSON array.');
            }
            if (property_exists($data, 'details') && $data->details !== null && ! is_object($data->details)) {
                $validator->errors()->add('data.details', 'The details field must be a JSON object.');
            }
        }];
    }

    public function expectedVersion(): string
    {
        return (string) $this->validated('expected_version');
    }

    /** @return array<string, mixed> the fields to change, as validated, with details in its wire shape */
    public function changes(): array
    {
        $changes = (array) $this->validated('data');
        $data = $this->rawData();
        if ($data !== null && property_exists($data, 'details')) {
            $changes['details'] = AgentApiJson::preserveShape($data->details);
        }

        return $changes;
    }

    private function rawData(): ?object
    {
        if (! $this->isJson()) {
            return null;
        }
        $payload = AgentApiJson::decodeRaw($this->getContent());

        return is_object($payload) && isset($payload->data) && is_object($payload->data) ? $payload->data : null;
    }
}
