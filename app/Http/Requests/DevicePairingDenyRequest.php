<?php

namespace App\Http\Requests;

use App\Models\PhrDevicePairingCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Re-validates the device-pairing approve page's hidden fields on a Deny
 * submission (DevicePairingController::deny()).
 *
 * Mints nothing, but still validates redirect_uri against the same allowlist
 * as Approve: the deny path redirects too, and must never be tricked into
 * redirecting anywhere but the one frozen destination.
 */
class DevicePairingDenyRequest extends FormRequest
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
            'device_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:100'],
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43,128}$/'],
            'redirect_uri' => ['required', 'string', 'in:'.PhrDevicePairingCode::ALLOWED_REDIRECT_URI],
        ];
    }

    /**
     * See DevicePairingShowRequest::failedValidation().
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The device-pairing request parameters are invalid.',
        ], 422));
    }
}
