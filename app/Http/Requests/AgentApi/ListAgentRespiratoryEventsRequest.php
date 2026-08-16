<?php

namespace App\Http\Requests\AgentApi;

use App\Models\PhrRespiratoryEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAgentRespiratoryEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    protected function prepareForValidation(): void
    {
        $value = $this->query('include_false_positives');
        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                $this->merge(['include_false_positives' => $normalized]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'updated_after' => ['sometimes', 'date'],
            'updated_before' => ['sometimes', 'date', 'after_or_equal:updated_after'],
            'occurred_after' => ['sometimes', 'date'],
            'occurred_before' => ['sometimes', 'date', 'after_or_equal:occurred_after'],
            'event_type' => ['sometimes', 'string', Rule::in(PhrRespiratoryEvent::EVENT_TYPES)],
            'include_false_positives' => ['sometimes', 'boolean'],
        ];
    }
}
