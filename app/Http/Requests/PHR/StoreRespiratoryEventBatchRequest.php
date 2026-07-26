<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class StoreRespiratoryEventBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * This endpoint is CSRF-exempt (bearer devices carry no session token), so
     * it must not accept form-encoded bodies: a cross-site hidden form could
     * otherwise ride a victim's session cookie to inject events. Cross-origin
     * JSON is blocked by CORS preflight, so requiring a JSON body closes the
     * CSRF gap.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            abort(415, 'This endpoint only accepts application/json request bodies.');
        }
    }

    /**
     * Only the envelope is validated here. Individual events are validated
     * per-event inside the controller so that one malformed event yields a
     * per-event `rejected` result without failing the whole batch.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.max' => 'A batch may contain at most 500 events.',
        ];
    }
}
