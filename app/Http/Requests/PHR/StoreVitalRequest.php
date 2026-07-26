<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class StoreVitalRequest extends FormRequest
{
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
            'vital_name' => ['required', 'string', 'max:255'],
            'vital_date' => ['nullable', 'date'],
            'observed_at' => ['nullable', 'date'],
            'vital_value' => ['nullable', 'string', 'max:255'],
            'value_numeric' => ['nullable', 'numeric'],
            'value_numeric_secondary' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'secondary_unit' => ['nullable', 'string', 'max:50'],
            'body_site' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
