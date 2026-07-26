<?php

namespace App\Http\Requests\PHR;

use App\Models\PhrPatientUserAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'exists:users,email'],
            'access_level' => ['required', 'string', Rule::in([
                PhrPatientUserAccess::LEVEL_MANAGER,
                PhrPatientUserAccess::LEVEL_VIEWER,
            ])],
        ];
    }
}
