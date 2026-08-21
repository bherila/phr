<?php

namespace App\Http\Requests\PHR;

use App\Support\PHR\Validation\LabResultDataRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreLabResultRequest extends FormRequest
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
        return LabResultDataRules::rules($this->isMethod('PATCH'));
    }
}
