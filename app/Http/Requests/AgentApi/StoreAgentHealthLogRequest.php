<?php

namespace App\Http\Requests\AgentApi;

use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\Http\Requests\PHR\StoreHealthLogRequest;

final class StoreAgentHealthLogRequest extends StoreHealthLogRequest
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
            ...$this->healthLogRules(false),
        ];
    }

    public function command(): HealthLogCreateData
    {
        return HealthLogCreateData::fromValidated($this->validated());
    }
}
