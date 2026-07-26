<?php

namespace App\Http\Requests\PHR;

use Illuminate\Foundation\Http\FormRequest;

class AcceptPhrGenAiResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payload' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        $payload = $this->validated('payload');

        return is_array($payload) ? $payload : null;
    }
}
