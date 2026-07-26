<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAllergyRequest extends FormRequest
{
    /** @var list<string> */
    private const array CATEGORIES = [
        'food',
        'medication',
        'environment',
        'biologic',
    ];

    /** @var list<string> */
    private const array CRITICALITIES = [
        'low',
        'high',
        'unable_to_assess',
    ];

    /** @var list<string> */
    private const array CLINICAL_STATUSES = [
        'active',
        'inactive',
        'resolved',
    ];

    /** @var list<string> */
    private const array VERIFICATION_STATUSES = [
        'unconfirmed',
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
            'substance' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:255'],
            'rxnorm_code' => ['nullable', 'string', 'max:50'],
            'snomed_code' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', Rule::in(self::CATEGORIES)],
            'criticality' => ['nullable', 'string', Rule::in(self::CRITICALITIES)],
            'clinical_status' => ['sometimes', 'string', Rule::in(self::CLINICAL_STATUSES)],
            'verification_status' => ['sometimes', 'string', Rule::in(self::VERIFICATION_STATUSES)],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', 'string', Rule::in(self::SEVERITIES)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }
}
