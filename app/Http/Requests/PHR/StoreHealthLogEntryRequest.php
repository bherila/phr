<?php

namespace App\Http\Requests\PHR;

use App\Rules\JsonObject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;

class StoreHealthLogEntryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->healthLogEntryRules(false);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'occurred_at.required' => 'Choose when this health event occurred.',
            'intensity.between' => 'Intensity must be between 0 and 10.',
            'tags.max' => 'An entry may have at most 20 tags.',
            'tags.*.distinct' => 'Tags must be unique.',
            'details.array' => 'Details must be a JSON object.',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->isJson()) {
                return;
            }
            try {
                $payload = json_decode($this->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return;
            }
            if (! is_object($payload) || ! property_exists($payload, 'details')) {
                return;
            }
            if ($payload->details !== null && ! is_object($payload->details)) {
                $validator->errors()->add('details', 'The details field must be a JSON object.');
            }
        }];
    }

    /** @return array<string, mixed> */
    protected function healthLogEntryRules(bool $updating): array
    {
        return [
            'occurred_at' => [$updating ? 'sometimes' : 'required', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'intensity' => ['nullable', 'integer', 'between:0,10'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50', 'distinct'],
            'details' => ['nullable', 'array', new JsonObject, 'max:50'],
        ];
    }
}
