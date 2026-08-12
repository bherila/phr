<?php

namespace App\Http\Requests;

use App\Models\PhrDevicePairingCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Re-validates the device-pairing approve page's hidden fields on submit
 * (DevicePairingController::approve()).
 *
 * The query-string values shown on the GET page (DevicePairingShowRequest)
 * are never trusted directly on this POST: they come back only as opaque
 * hidden form fields, so a tampered submission (e.g. a different
 * redirect_uri) is caught here exactly like it would be on the original GET.
 */
class DevicePairingApproveRequest extends FormRequest
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
     * See DevicePairingShowRequest::failedValidation() — same reasoning: a
     * deterministic 422, never a redirect anywhere (including the custom
     * scheme).
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The device-pairing request parameters are invalid.',
        ], 422));
    }
}
