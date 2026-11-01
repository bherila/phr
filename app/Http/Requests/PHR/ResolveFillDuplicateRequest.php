<?php

namespace App\Http\Requests\PHR;

use App\Services\PHR\Fills\MedicationFillDuplicateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A person's answer to a fill flagged as a possible duplicate, conditional on the version they saw. */
class ResolveFillDuplicateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in([MedicationFillDuplicateService::SAME, MedicationFillDuplicateService::DIFFERENT])],
            'version' => ['required', 'string', 'size:64'],
            'candidate_fill_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
