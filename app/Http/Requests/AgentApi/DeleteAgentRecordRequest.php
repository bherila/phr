<?php

namespace App\Http\Requests\AgentApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Every single-record agent delete (clinical.delete, documents.delete,
 * health_logs.delete, health_log_entries.delete): removing a record is as
 * consequential as changing it, so it takes the same precondition, the version
 * the caller read.
 */
final class DeleteAgentRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    public function expectedVersion(): string
    {
        return (string) $this->validated('expected_version');
    }
}
