<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrMedicationFill;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A prescription fill as the web and agent APIs return it. Stored casing is
 * returned as is: "force lowercase" is a display preference applied when the
 * interface renders, never here.
 */
class MedicationFillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrMedicationFill $fill */
        $fill = $this->resource;
        $medication = $fill->medication;

        return [
            'id' => $fill->id,
            'patient_id' => $fill->patient_id,
            'user_id' => $fill->user_id,
            'medication_id' => $fill->medication_id,
            'medication' => $medication === null ? null : ['id' => $medication->id, 'name' => $medication->name],
            'import_source' => $fill->import_source,
            'external_id' => $fill->external_id,
            'source_kind' => $fill->source_kind,
            'source_document_id' => $fill->source_document_id,
            'source_locator' => $fill->source_locator,
            'review_status' => $fill->review_status,
            'patient_name_as_printed' => $fill->patient_name_as_printed,
            'ndc_as_captured' => $fill->ndc_as_captured,
            'ndc_10' => $fill->ndc_10,
            'ndc_11' => $fill->ndc_11,
            'ndc_status' => $fill->ndc_status,
            'drug' => [
                'substance_names' => $fill->substance_names ?? [],
                'strengths' => $fill->strengths ?? [],
                'brand_name' => $fill->brand_name,
                'dosage_form' => $fill->dosage_form,
                'route' => $fill->route,
                'labeler_name' => $fill->labeler_name,
                'package_description' => $fill->package_description,
                'rxcui' => $fill->rxcui,
            ],
            'drug_as_printed' => $fill->drug_as_printed,
            'filled_on' => $fill->filled_on->toDateString(),
            'quantity' => self::decimal($fill->quantity),
            'quantity_unit' => $fill->quantity_unit,
            'days_supply' => $fill->days_supply,
            'rx_number' => $fill->rx_number,
            'refills_remaining' => $fill->refills_remaining,
            'directions' => $fill->directions,
            'prescriber_name' => $fill->prescriber_name,
            'paid_cents' => $fill->paid_cents,
            'retail_cents' => $fill->retail_cents,
            'pharmacy_name' => $fill->pharmacy_name,
            'pharmacy_location' => $fill->pharmacy_location,
            'version' => app(AgentClinicalRecordVersion::class)->for($fill),
            'created_at' => $fill->created_at?->toDateTimeString(),
            'updated_at' => $fill->updated_at?->toDateTimeString(),
        ];
    }

    /** A stored decimal without trailing zeros: "90.000" is "90", "0.500" is "0.5". */
    private static function decimal(mixed $value): string
    {
        $text = (string) $value;
        if (! str_contains($text, '.')) {
            return $text;
        }

        return rtrim(rtrim($text, '0'), '.');
    }
}
