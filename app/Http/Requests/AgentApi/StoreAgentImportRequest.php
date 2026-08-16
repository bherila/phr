<?php

namespace App\Http\Requests\AgentApi;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAgentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'document_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
