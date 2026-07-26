<?php

namespace App\Http\Requests\PHR;

use App\Models\PhrHealthLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHealthLogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->healthLogRules(false);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give this health log a name.',
            'name.unique' => 'A health log with this name already exists for the patient.',
            'kind.in' => 'Choose a supported health log kind.',
            'description.max' => 'The description may not exceed 1,000 characters.',
        ];
    }

    /** @return array<string, mixed> */
    protected function healthLogRules(bool $updating): array
    {
        return [
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:120'],
            'kind' => [$updating ? 'sometimes' : 'required', 'string', Rule::in(PhrHealthLog::KINDS)],
            'description' => ['nullable', 'string', 'max:1000'],
            'archived_at' => ['nullable', 'date'],
        ];
    }
}
