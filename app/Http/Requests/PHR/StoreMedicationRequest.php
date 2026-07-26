<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicationRequest extends FormRequest
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
            'name' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'string', 'max:255'],
            'rxnorm_code' => ['nullable', 'string', 'max:50'],
            'dose' => ['nullable', 'string', 'max:100'],
            'dose_unit' => ['nullable', 'string', 'max:50'],
            'route' => ['nullable', 'string', 'max:100'],
            'frequency' => ['nullable', 'string', 'max:100'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'completed', 'discontinued', 'on_hold'])],
            'prescriber_name' => ['nullable', 'string', 'max:255'],
            'reason_for_use' => ['nullable', 'string', 'max:10000'],
            'raw_text' => ['nullable', 'string'],
        ];
    }
}
