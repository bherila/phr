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
            'range_unit' => $labResult->range_unit,
            'reference_range_text' => $labResult->reference_range_text,
            'normal_value' => $labResult->normal_value,
            'abnormal_flag' => $labResult->abnormal_flag,
            'message_from_provider' => $labResult->message_from_provider,
            'result_comment' => $labResult->result_comment,
            'lab_director' => $labResult->lab_director,
            'source' => $labResult->source,
            'notes' => $labResult->notes,
            'created_at' => $labResult->created_at?->toDateTimeString(),
            'updated_at' => $labResult->updated_at?->toDateTimeString(),
        ];
    }
}
