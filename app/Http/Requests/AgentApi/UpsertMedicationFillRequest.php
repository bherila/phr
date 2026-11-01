<?php

namespace App\Http\Requests\AgentApi;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\Services\PHR\Fills\MedicationFillExternalId;
use App\Support\PHR\Validation\MedicationFillDataRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Like the generic clinical upsert, except that the external ID is optional (the
 * server derives it) and provenance is required: every fill says what kind of
 * source it came from, and a source that is not manual points at its document.
 */
final class UpsertMedicationFillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $dataRules = [];
        $fillRules = MedicationFillDataRules::rules();
        foreach ($fillRules as $field => $rules) {
            $dataRules['data.'.$field] = $rules;
        }

        return [
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\A[^\p{C}]+\z/u'],
            'source_document_id' => ['present', 'nullable', 'integer', 'min:1'],
            'review_status' => ['prohibited'],
            'expected_version' => ['present', 'nullable', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'data' => ['required', 'array:'.implode(',', array_keys($fillRules))],
            // The agent states where the fill was read; the browser forces "manual".
            'data.allow_unresolved' => ['prohibited'],
            ...$dataRules,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = (array) $this->input('data');
            $documentId = $this->input('source_document_id');
            if (($data['source_kind'] ?? null) !== 'manual' && $documentId === null) {
                $validator->errors()->add('source_document_id', 'A fill read from a label photo, receipt or CSV must name the uploaded source document (upload it with documents_upload first).');
            }
            if ($this->externalId() === null) {
                $validator->errors()->add('external_id', 'Provide an external_id, or the pharmacy_name, rx_number and filled_on (or a source_document_id) the server can derive one from.');
            }
        });
    }

    public function upsertData(): ClinicalUpsertData
    {
        $validated = $this->validated();
        $validated['external_id'] = $this->externalId();

        return ClinicalUpsertData::fromValidated('medication-fills', $validated);
    }

    private function externalId(): ?string
    {
        $given = $this->input('external_id');
        if (is_string($given) && $given !== '') {
            return $given;
        }
        $documentId = $this->input('source_document_id');

        return MedicationFillExternalId::derive((array) $this->input('data'), is_numeric($documentId) ? (int) $documentId : null);
    }
}
