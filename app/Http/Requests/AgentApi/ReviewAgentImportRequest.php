<?php

namespace App\Http\Requests\AgentApi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewAgentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['accept', 'reject'])],
            'payload' => [
                'sometimes',
                'array',
                Rule::prohibitedIf(fn (): bool => $this->input('action') === 'reject'),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function payload(): ?array
    {
        $payload = $this->validated('payload');

        return is_array($payload) ? $payload : null;
    }
}
