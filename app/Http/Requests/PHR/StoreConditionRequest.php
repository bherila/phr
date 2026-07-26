<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConditionRequest extends FormRequest
{
    /** @var list<string> */
    private const array CLINICAL_STATUSES = [
        'active',
        'recurrence',
        'relapse',
        'inactive',
        'remission',
        'resolved',
    ];

    /** @var list<string> */
    private const array VERIFICATION_STATUSES = [
        'unconfirmed',
        'provisional',
        'differential',
        'confirmed',
        'refuted',
        'entered_in_error',
    ];

    /** @var list<string> */
    private const array SEVERITIES = [
        'mild',
        'moderate',
        'severe',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:255'],
            'icd10_code' => ['nullable', 'string', 'max:20'],
            'snomed_code' => ['nullable', 'string', 'max:50'],
            'onset_date' => ['nullable', 'date'],
            'abated_date' => ['nullable', 'date'],
            'clinical_status' => ['sometimes', 'string', Rule::in(self::CLINICAL_STATUSES)],
            'verification_status' => ['sometimes', 'string', Rule::in(self::VERIFICATION_STATUSES)],
            'severity' => ['nullable', 'string', Rule::in(self::SEVERITIES)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }
}
