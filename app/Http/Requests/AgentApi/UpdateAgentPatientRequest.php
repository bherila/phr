<?php

namespace App\Http\Requests\AgentApi;

use App\Http\Requests\PHR\UpdatePatientRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * patients.update: the browser's patient edit ({@see UpdatePatientRequest}) under
 * `data`, guarded by the patient's current version. Real names are left out, as on
 * patients.create: they decide which chart a document lands on, so only a person
 * sets them. Only the fields sent change, so display_name may be omitted, but not
 * cleared.
 */
final class UpdateAgentPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $fields = Arr::except((new UpdatePatientRequest)->rules(), ['real_names', 'real_names.*']);
        $dataRules = [];
        foreach ($fields as $field => $rules) {
            $dataRules['data.'.$field] = in_array('required', $rules, true) ? ['sometimes', ...$rules] : $rules;
        }

        return [
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'data' => ['required', 'array:'.implode(',', array_keys($fields)), 'min:1'],
            ...$dataRules,
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
