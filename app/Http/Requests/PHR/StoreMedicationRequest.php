<?php

namespace App\Http\Requests\PHR;

use App\Support\PHR\Validation\MedicationDataRules;
use Illuminate\Foundation\Http\FormRequest;

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
        return MedicationDataRules::rules($this->isMethod('PATCH'));
    }
}
