<?php

namespace App\Http\Requests\AgentApi;

use App\Services\PHR\Fills\MedicationFillDuplicateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolveFillDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in([MedicationFillDuplicateService::SAME, MedicationFillDuplicateService::DIFFERENT])],
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'candidate_fill_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
