<?php

namespace App\Http\Requests\AgentApi;

use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\Http\Requests\Concerns\RejectsUnknownInputFields;
use App\Http\Requests\PHR\StoreHealthLogRequest;
use Illuminate\Validation\Validator;

final class StoreAgentHealthLogRequest extends StoreHealthLogRequest
{
    use RejectsUnknownInputFields;

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

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectUnknownInputFields($validator, $this->rules())];
    }
}
