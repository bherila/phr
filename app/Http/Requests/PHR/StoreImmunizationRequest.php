<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class StoreImmunizationRequest extends FormRequest
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
            'vaccine_name' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:255'],
            'cvx_code' => ['nullable', 'string', 'max:20'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'lot_number' => ['nullable', 'string', 'max:100'],
            'administered_on' => ['nullable', 'date'],
            'dose_number' => ['nullable', 'integer', 'min:1'],
            'series_doses' => ['nullable', 'integer', 'min:1'],
            'site' => ['nullable', 'string', 'max:100'],
            'route' => ['nullable', 'string', 'max:100'],
            'administered_by' => ['nullable', 'string', 'max:255'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }
}
