<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class StoreSinusEnrollmentBatchRequest extends FormRequest
{
    /**
     * Deliberately smaller than the 500-event batch limit. Enrollments carry
     * embeddings: 500 x 16 KB would be ~10.9 MB of base64, over PHP's default
     * 8 MB post_max_size. At YAMNet's real 1024 dims (~5.5 KB base64 per item)
     * 100 items is ~550 KB, comfortably inside stock limits.
     */
    public const MAX_BATCH = 100;

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
     * Only the envelope is validated here; individual enrollments are validated
     * per-item in the controller so one malformed item yields a `rejected`
     * result instead of failing the whole batch.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enrollments' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'enrollments.*' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'enrollments.max' => 'A batch may contain at most '.self::MAX_BATCH.' enrollments.',
        ];
    }
}
