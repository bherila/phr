<?php

namespace App\Http\Requests\AgentApi;

use App\Http\Requests\PHR\StorePhrDocumentRequest;

final class StoreAgentDocumentRequest extends StorePhrDocumentRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'external_id' => ['required', 'string', 'max:255', 'regex:/^[^\p{C}]+$/u'],
        ];
    }
}
