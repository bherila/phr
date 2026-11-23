<?php

namespace App\Http\Requests\AgentApi;

use App\Http\Requests\PHR\UpdateHealthLogRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * health_logs.update: the browser's log edit ({@see UpdateHealthLogRequest}) under
 * `data`, guarded by the log's current version.
 */
final class UpdateAgentHealthLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = (new UpdateHealthLogRequest)->rules();
        $dataRules = [];
        foreach ($fields as $field => $rules) {
            $dataRules['data.'.$field] = $rules;
        }

        return [
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'data' => ['required', 'array:'.implode(',', array_keys($fields)), 'min:1'],
            ...$dataRules,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'data.kind.in' => 'Choose a supported health log kind.',
            'data.description.max' => 'The description may not exceed 1,000 characters.',
        ];
    }

    public function expectedVersion(): string
    {
        return (string) $this->validated('expected_version');
    }

    /** @return array<string, mixed> the fields to change, as validated */
    public function changes(): array
    {
        return (array) $this->validated('data');
    }
}
