<?php

namespace App\Http\Requests\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalRecordUpdateData;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateClinicalRecordRequest extends FormRequest
{
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
        $clinicalRules = $ruleClass::rules(true);
        foreach ($clinicalRules as $field => $rules) {
            $dataRules['data.'.$field] = $rules;
        }
        $allowedDataKeys = array_values(array_unique(array_map(
            static fn (string $field): string => explode('.', $field, 2)[0],
            array_keys($clinicalRules),
        )));

        return [
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'source_document_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Confirmation is a browser review action. The server owns this
            // column on every agent edit, so the field is refused rather than
            // silently ignored.
            'review_status' => ['prohibited'],
            'data' => ['sometimes', 'array:'.implode(',', $allowedDataKeys), 'min:1'],
            ...$dataRules,
        ];
    }

    public function updateData(): ClinicalRecordUpdateData
    {
        return ClinicalRecordUpdateData::fromValidated(
            (string) $this->route('resource'),
            $this->validated(),
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('source_document_id') && ! $this->exists('data')) {
                $validator->errors()->add('data', 'At least one mutable field is required.');
            }
        });
    }
}
