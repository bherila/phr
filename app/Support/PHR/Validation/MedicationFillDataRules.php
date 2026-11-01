<?php

namespace App\Support\PHR\Validation;

use App\Contracts\PHR\ClinicalDataRules;
use App\Models\PhrMedicationFill;
use Illuminate\Validation\Rule;

/**
 * What a client may say about a prescription fill. The drug itself is never
 * among it: the server resolves substances, strengths, brand, form, route,
 * labeler and package from the NDC, so a client sends the NDC (or the package
 * it chose among candidates) and the fill.
 */
final class MedicationFillDataRules implements ClinicalDataRules
{
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            ...($partial ? [] : [
                // The EOB line that adjudicated the fill. It is evidence, not a column: the
                // server records it beside the fill's other sources (see MedicationFillEvidenceService).
                'eob_line_id' => ['nullable', 'integer', 'min:1'],
            ]),
            'ndc' => ['nullable', 'string', 'max:64', 'regex:/\A[0-9A-Za-z \x{2010}-\x{2015}\x{2212}:#-]+\z/u'],
            'ndc_candidate' => ['nullable', 'string', 'max:16', 'regex:/\A[0-9]+-[0-9]+-[0-9]+\z/'],
            'allow_unresolved' => ['sometimes', 'boolean'],
            'medication_id' => ['nullable', 'integer', 'min:1'],
            'drug_as_printed' => ['nullable', 'string', 'max:255'],
            'patient_name_as_printed' => ['nullable', 'string', 'max:255'],
            'filled_on' => [$required, 'date'],
            'quantity' => [$required, 'numeric', 'min:0', 'max:999999999', 'decimal:0,3'],
            'quantity_unit' => ['nullable', 'string', 'max:50'],
            'days_supply' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'rx_number' => ['nullable', 'string', 'max:64'],
            'refills_remaining' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'directions' => ['nullable', 'string', 'max:10000'],
            'prescriber_name' => ['nullable', 'string', 'max:255'],
            'paid_cents' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'retail_cents' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'pharmacy_name' => ['nullable', 'string', 'max:255'],
            'pharmacy_location' => ['nullable', 'string', 'max:255'],
            'source_kind' => [$required, 'string', Rule::in(PhrMedicationFill::SOURCE_KINDS)],
            'source_locator' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function jsonSchema(): array
    {
        return ClinicalJsonSchema::object([
            'eob_line_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'ndc' => ClinicalJsonSchema::nullableString(maxLength: 64),
            'ndc_candidate' => ['type' => ['string', 'null'], 'maxLength' => 16, 'pattern' => '^[0-9]+-[0-9]+-[0-9]+$'],
            'allow_unresolved' => ['type' => 'boolean'],
            'medication_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'drug_as_printed' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'patient_name_as_printed' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'filled_on' => ['type' => 'string', 'format' => 'date'],
            'quantity' => ['type' => ['number', 'string'], 'minimum' => 0],
            'quantity_unit' => ClinicalJsonSchema::nullableString(maxLength: 50),
            'days_supply' => ['type' => ['integer', 'null'], 'minimum' => 0],
            'rx_number' => ClinicalJsonSchema::nullableString(maxLength: 64),
            'refills_remaining' => ['type' => ['integer', 'null'], 'minimum' => 0],
            'directions' => ClinicalJsonSchema::nullableString(maxLength: 10000),
            'prescriber_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'paid_cents' => ['type' => ['integer', 'null'], 'minimum' => 0],
            'retail_cents' => ['type' => ['integer', 'null'], 'minimum' => 0],
            'pharmacy_name' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'pharmacy_location' => ClinicalJsonSchema::nullableString(maxLength: 255),
            'source_kind' => ['type' => 'string', 'enum' => PhrMedicationFill::SOURCE_KINDS],
            'source_locator' => ClinicalJsonSchema::nullableString(maxLength: 255),
        ], ['filled_on', 'quantity', 'source_kind']);
    }
}
