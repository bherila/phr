<?php

namespace App\Support\AgentApi;

use App\Services\PHR\Eob\EobClaim;
use App\Support\PHR\Validation\ClinicalJsonSchema;
use App\Support\PHR\Validation\MedicationFillDataRules;
use Illuminate\Validation\Rule;

/** What an agent may say about an EOB claim and its lines. */
final class EobWriteRules
{
    /**
     * Rules for the `data` object of an upsert (every claim needs a type and at
     * least one line) or of an update (only what changes).
     *
     * @return array<string, mixed>
     */
    public static function data(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $money = ['nullable', 'numeric', 'between:-99999999999.99,99999999999.99', 'decimal:0,2'];
        $rules = [
            'data' => ['required', 'array:'.implode(',', ['claim_type', ...EobClaim::HEADER_TEXT, ...EobClaim::HEADER_DATES, ...EobClaim::HEADER_MONEY, 'lines'])],
            'data.claim_type' => [$required, 'string', Rule::in(EobClaim::CLAIM_TYPES)],
            'data.claim_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'data.administrator' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.carrier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.plan_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.group_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'data.member_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'data.participant_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.patient_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.provider_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.provider_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'data.payment_to' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.provider_tin' => ['sometimes', 'nullable', 'string', 'max:50'],
            'data.check_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'data.lines' => [$required, 'array', 'min:1', 'max:500'],
            'data.lines.*' => ['array'],
            'data.lines.*.line_number' => ['sometimes', 'integer', 'min:1', 'max:100000', 'distinct'],
            'data.lines.*.procedure_code' => ['required', 'string', 'min:1', 'max:30'],
            'data.lines.*.revenue_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'data.lines.*.code_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'data.lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'data.lines.*.service_start' => ['sometimes', 'nullable', 'date'],
            'data.lines.*.service_end' => ['sometimes', 'nullable', 'date'],
            'data.lines.*.notes_applied' => ['sometimes', 'nullable', 'array', 'max:20'],
            'data.lines.*.notes_applied.*' => ['string', 'max:50'],
        ];
        foreach (EobClaim::HEADER_DATES as $key) {
            $rules["data.{$key}"] = ['sometimes', 'nullable', 'date'];
        }
        foreach (EobClaim::HEADER_MONEY as $key) {
            $rules["data.{$key}"] = ['sometimes', ...$money];
        }
        foreach (EobClaim::LINE_MONEY as $key) {
            $rules["data.lines.*.{$key}"] = ['sometimes', ...$money];
        }

        if ($partial) {
            // Fills are written with the line they belong to on upsert; a correction
            // links a fill to a line with medication_fills.upsert and its eob_line_id.
            $rules['data.lines.*.fill'] = ['prohibited'];
        } else {
            $rules['data.lines.*.fill'] = ['sometimes', 'nullable', 'array'];
            foreach (self::fillRules() as $field => $fieldRules) {
                $rules["data.lines.*.fill.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * What a pharmacy line may say about the fill it adjudicated: the fill fields
     * of medication_fills.upsert, with the fill date, pharmacy and drug text
     * defaulting from the line and the claim. The source is always the EOB.
     *
     * @return array<string, mixed>
     */
    public static function fillRules(): array
    {
        $rules = MedicationFillDataRules::rules();
        unset($rules['source_kind'], $rules['eob_line_id'], $rules['allow_unresolved']);
        $rules['filled_on'] = ['sometimes', 'nullable', 'date'];

        return $rules;
    }

    /** @return list<string> */
    public static function fillFields(): array
    {
        return array_keys(self::fillRules());
    }

    /**
     * The JSON Schema of the `data` object, shared by the OpenAPI document and the MCP tools.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(bool $partial): array
    {
        $money = ['type' => ['string', 'number', 'null'], 'description' => 'A decimal amount with at most two places, e.g. 12.50.'];
        $header = [
            'claim_type' => ['type' => 'string', 'enum' => EobClaim::CLAIM_TYPES],
            'claim_number' => ClinicalJsonSchema::nullableString(maxLength: 80),
            'administrator' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'carrier' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'plan_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'group_number' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'member_id' => ClinicalJsonSchema::nullableString(maxLength: 100),
            'participant_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'patient_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'provider_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'provider_phone' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'payment_to' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'provider_tin' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'check_number' => ClinicalJsonSchema::nullableString(maxLength: 100),
        ];
        foreach (EobClaim::HEADER_DATES as $key) {
            $header[$key] = ClinicalJsonSchema::nullableString('date');
        }
        foreach (EobClaim::HEADER_MONEY as $key) {
            $header[$key] = $money;
        }

        $line = [
            'line_number' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Defaults to the line\'s position, from 1.'],
            'procedure_code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30, 'description' => 'CPT, HCPCS or CDT code; for a pharmacy line, the NDC or Rx number the claim prints.'],
            'revenue_code' => ClinicalJsonSchema::nullableString(maxLength: 30),
            'code_type' => ClinicalJsonSchema::nullableString(maxLength: 40),
            'description' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'service_start' => ClinicalJsonSchema::nullableString('date'),
            'service_end' => ClinicalJsonSchema::nullableString('date'),
            'notes_applied' => ['type' => ['array', 'null'], 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 50]],
        ];
        foreach (EobClaim::LINE_MONEY as $key) {
            $line[$key] = $money;
        }
        if (! $partial) {
            $fill = MedicationFillDataRules::jsonSchema();
            $properties = array_diff_key($fill['properties'], ['source_kind' => true, 'eob_line_id' => true, 'allow_unresolved' => true]);
            $properties['filled_on'] = ClinicalJsonSchema::nullableString('date');
            $line['fill'] = [
                'type' => ['object', 'null'],
                'description' => 'The prescription fill this pharmacy line adjudicated. filled_on defaults to service_start, pharmacy_name to the claim\'s provider_name, drug_as_printed to the line description. Never send drug fields: send the NDC.',
                'properties' => $properties,
                'required' => ['quantity'],
                'additionalProperties' => false,
            ];
        }

        $schema = ClinicalJsonSchema::object([
            ...$header,
            'lines' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 500,
                'items' => ClinicalJsonSchema::object($line, ['procedure_code']),
                'description' => $partial ? 'Replaces every line when present.' : 'The claim\'s service lines.',
            ],
        ], $partial ? [] : ['claim_type', 'lines']);

        return $schema;
    }
}
