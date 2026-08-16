<?php

namespace App\Http\Requests\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertClinicalRecordRequest extends FormRequest
{
    private const array REVIEW_STATUSES = ['pending_review', 'confirmed'];

    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $resource = (string) $this->route('resource');
        $definition = AgentClinicalResourceCatalog::definition($resource);
        $ruleClass = $definition['write_rules'] ?? null;

        abort_unless(is_string($ruleClass), 404);

        $dataRules = [];
        $clinicalRules = $ruleClass::rules();
        foreach ($clinicalRules as $field => $rules) {
            $dataRules['data.'.$field] = $rules;
        }
        $allowedDataKeys = array_values(array_unique(array_map(
            static fn (string $field): string => explode('.', $field, 2)[0],
            array_keys($clinicalRules),
        )));

        return [
            'external_id' => ['required', 'string', 'max:255', 'regex:/\A[^\p{C}]+\z/u'],
            'source_document_id' => ['present', 'nullable', 'integer', 'min:1'],
            'review_status' => ['required', 'string', Rule::in(self::REVIEW_STATUSES)],
            'expected_version' => ['present', 'nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'data' => ['required', 'array:'.implode(',', $allowedDataKeys)],
            ...$dataRules,
        ];
    }

    public function upsertData(): ClinicalUpsertData
    {
        return ClinicalUpsertData::fromValidated(
            (string) $this->route('resource'),
            $this->validated(),
        );
    }
}
