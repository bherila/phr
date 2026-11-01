<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrMedicationFill;
use App\Services\PHR\Ndc\FillDisplayCasing;
use App\Support\AgentApi\AgentClinicalRecordVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A prescription fill as the web and agent APIs return it. Stored values are
 * returned as is; `display` carries the same captured text with default display
 * casing applied (FillDisplayCasing), for clients to render.
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
            // Why a person should look closely, beyond the fill being new: it may duplicate another.
            'review_flags' => $fill->evidence->where('source_kind', 'possible_duplicate')->whereNotNull('related_fill_id')->map(
                static fn ($flag): array => [
                    'code' => 'possible_duplicate',
                    'message' => "This may be the same fill as fill {$flag->related_fill_id}: it matches on the fill date and drug, but the pharmacy, quantity or Rx number do not settle it. Keep one and reject the other if they are the same.",
                    'fill_id' => (int) $flag->related_fill_id,
                ],
            )->values()->all(),
            // The EOB lines that adjudicated this fill, and the other documents that show it.
            'eob_line_ids' => $fill->evidence->whereNotNull('eob_line_id')->pluck('eob_line_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'evidence_document_ids' => $fill->evidence->whereNotNull('document_id')->pluck('document_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
            'display' => FillDisplayCasing::forFill($fill),
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
