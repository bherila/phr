<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class DeleteSinusEnrollmentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * CSRF-exempt (bearer devices carry no session token), so form-encoded
     * bodies must be refused — see StoreRespiratoryEventBatchRequest.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            abort(415, 'This endpoint only accepts application/json request bodies.');
        }
    }

    /**
     * Uuids are base64-encoded raw 16-byte values; the decode is validated in
     * the controller so a malformed entry reports `not_found` rather than
     * failing the batch.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['string', 'max:32'],
        ];
    }
}
