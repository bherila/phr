<?php

namespace App\Http\Requests;

use App\Models\PhrDevicePairingCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates the device-pairing approve page's query string
 * (DevicePairingController::show()).
 *
 * `redirect_uri` is checked against PhrDevicePairingCode::ALLOWED_REDIRECT_URI
 * exactly, not a URL-shape pattern: the app's custom scheme has none of the
 * same-origin protections an https redirect gets, so anything other than the
 * one frozen value must be a hard rejection — this controller must never
 * construct a redirect to an unrecognized destination, not even to report
 * the error.
 */
class DevicePairingShowRequest extends FormRequest
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
     * Forces a 422 with a plain message regardless of the request's Accept
     * header. The default FormRequest behavior for a non-JSON GET request
     * would redirect back to the (nonexistent) previous page instead — never
     * to the custom scheme, but also not the deterministic 422 this feature's
     * wire contract requires.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The device-pairing request parameters are invalid.',
        ], 422));
    }
}
