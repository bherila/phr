<?php

namespace App\Http\Resources\PHR;

use App\Models\PhrLabResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PhrLabResult $labResult */
        $labResult = $this->resource;

        return [
            'id' => $labResult->id,
            'patient_id' => $labResult->patient_id,
            'user_id' => $labResult->user_id,
            'import_source' => $labResult->import_source,
            'external_id' => $labResult->external_id,
            'source_document_id' => $labResult->source_document_id,
            'review_status' => $labResult->review_status,
            'test_name' => $labResult->test_name,
            'collection_datetime' => $labResult->collection_datetime?->toDateTimeString(),
            'result_datetime' => $labResult->result_datetime?->toDateTimeString(),
            'result_status' => $labResult->result_status,
            'ordering_provider' => $labResult->ordering_provider,
            'resulting_lab' => $labResult->resulting_lab,
            'analyte' => $labResult->analyte,
            'value' => $labResult->value,
            'value_numeric' => $labResult->value_numeric,
            'unit' => $labResult->unit,
            'range_min' => $labResult->range_min,
            'range_max' => $labResult->range_max,
            'range_min_inclusive' => $labResult->range_min_inclusive,
            'range_max_inclusive' => $labResult->range_max_inclusive,
            'range_unit' => $labResult->range_unit,
            'reference_range_text' => $labResult->reference_range_text,
            'normal_value' => $labResult->normal_value,
            'abnormal_flag' => $labResult->abnormal_flag,
            'message_from_provider' => $labResult->message_from_provider,
            'result_comment' => $labResult->result_comment,
            'lab_director' => $labResult->lab_director,
            'source' => $labResult->source,
            'notes' => $labResult->notes,
            'value_raw' => $labResult->value_raw,
            'value_comparator' => $labResult->value_comparator,
            'analyte_code' => $labResult->analyte_code,
            'analyte_code_system' => $labResult->analyte_code_system,
            'collected_at_source' => $labResult->collected_at_source,
            'collected_at_precision' => $labResult->collected_at_precision,
            'collected_at_timezone' => $labResult->collected_at_timezone,
            'resulted_at_source' => $labResult->resulted_at_source,
            'resulted_at_precision' => $labResult->resulted_at_precision,
            'resulted_at_timezone' => $labResult->resulted_at_timezone,
            'created_at' => $labResult->created_at?->toDateTimeString(),
            'updated_at' => $labResult->updated_at?->toDateTimeString(),
        ];
    }
}
