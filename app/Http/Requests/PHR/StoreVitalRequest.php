<?php

namespace App\Http\Requests\PHR;

use App\Support\PHR\Validation\VitalDataRules;
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
        return VitalDataRules::rules($this->isMethod('PATCH'));
    }
}
