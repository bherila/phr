<?php

namespace App\Http\Requests\AgentApi;

use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Foundation\Http\FormRequest;

final class RetractClinicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $definition = AgentClinicalResourceCatalog::definition((string) $this->route('resource'));
        abort_unless(isset($definition['write_rules']), 404);

        return [
            // Withdrawing a record is as consequential as changing it, so it
            // takes the same precondition: the caller must be holding the
            // version it means to retract.
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    public function expectedVersion(): string
    {
        return (string) $this->validated('expected_version');
    }
}
