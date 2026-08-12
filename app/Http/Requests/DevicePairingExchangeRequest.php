<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the /api/device-pairing/exchange body
 * (DevicePairingExchangeController::exchange()).
 *
 * This endpoint is CSRF-exempt and carries no session — the Mac app has no
 * cookie jar — so it must not accept form-encoded bodies: a cross-site hidden
 * form could otherwise ride a victim's session cookie in and probe pairing
 * codes. Cross-origin JSON is blocked by CORS preflight, so requiring a JSON
 * body closes the CSRF gap (same pairing as StoreRespiratoryEventBatchRequest).
 */
class DevicePairingExchangeRequest extends FormRequest
{
    /**
     * No session and no prior authentication — the code + verifier pair is
     * the credential this endpoint checks, not who is asking.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            abort(415, 'This endpoint only accepts application/json request bodies.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'code_verifier' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:64'],
        ];
    }
}
