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
    /**
     * `email` is deliberately not validated with `exists:users,email`.
     *
     * That rule turned this endpoint into an account-enumeration oracle: any
     * authenticated user could probe an arbitrary address against their own
     * throwaway patient and read registration status straight off the
     * validation error. The controller now resolves the address and responds
     * identically whether or not it belongs to an account, matching
     * LoginController::requestEmailCode.
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'access_level' => ['required', 'string', Rule::in([
                PhrPatientUserAccess::LEVEL_MANAGER,
                PhrPatientUserAccess::LEVEL_VIEWER,
            ])],
        ];
    }
}
