<?php

namespace App\Http\Requests\PHR;

use App\Models\PhrRespiratoryEvent;
use Illuminate\Foundation\Http\FormRequest;

class FlagRespiratoryEventBatchRequest extends FormRequest
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
     * A flag is fully declarative, so the device can also *clear* one:
     * `false_positive: false` with a null `corrected_to` is how Undo syncs.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.uuid' => ['required', 'string', 'max:64'],
            'items.*.false_positive' => ['required', 'boolean'],
            'items.*.corrected_to' => [
                'present',
                'nullable',
                'string',
                'in:'.implode(',', PhrRespiratoryEvent::EVENT_TYPES),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.max' => 'A batch may contain at most 500 items.',
        ];
    }
}
