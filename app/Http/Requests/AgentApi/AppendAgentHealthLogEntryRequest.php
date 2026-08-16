<?php

namespace App\Http\Requests\AgentApi;

use App\DataTransferObjects\AgentApi\HealthLogEntryAppendData;
use App\Http\Requests\PHR\StoreHealthLogEntryRequest;

final class AppendAgentHealthLogEntryRequest extends StoreHealthLogEntryRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:255', 'regex:/\A[^\p{C}]+\z/u'],
            ...$this->healthLogEntryRules(false),
        ];
    }

    public function command(): HealthLogEntryAppendData
    {
        return HealthLogEntryAppendData::fromValidated($this->validated());
    }
}
