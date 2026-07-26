<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class StoreOfficeVisitRequest extends FormRequest
{
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
            'visit_date' => ['nullable', 'date'],
            'visit_started_at' => ['nullable', 'date'],
            'visit_ended_at' => ['nullable', 'date'],
            'visit_type' => ['nullable', 'string', 'max:100'],
            'provider_name' => ['nullable', 'string', 'max:255'],
            'provider_specialty' => ['nullable', 'string', 'max:100'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'chief_complaint' => ['nullable', 'string', 'max:10000'],
            'assessment' => ['nullable', 'string', 'max:10000'],
            'plan' => ['nullable', 'string', 'max:10000'],
            'subjective' => ['nullable', 'string', 'max:10000'],
            'objective' => ['nullable', 'string', 'max:10000'],
            'icd10_codes' => ['nullable', 'array'],
            'cpt_codes' => ['nullable', 'array'],
        ];
    }
}
