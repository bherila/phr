<?php

namespace App\Http\Requests\PHR;

use App\Support\PHR\Validation\ConditionDataRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreConditionRequest extends FormRequest
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
        return ConditionDataRules::rules($this->isMethod('PATCH'));
    }
}
