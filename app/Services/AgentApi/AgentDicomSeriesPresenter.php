<?php

namespace App\Services\AgentApi;

use App\Models\PhrDicomSeries;

/** Metadata-only DICOM series representation for automation clients. */
final class AgentDicomSeriesPresenter
{
    /** @return array<string, mixed> */
    public function payload(PhrDicomSeries $series): array
    {
        return [
            'id' => $series->id,
            'patient_id' => $series->patient_id,
            'study_id' => $series->study_id,
            'series_instance_uid' => $series->series_instance_uid,
            'modality' => $series->modality,
            'series_number' => $series->series_number,
            'description' => $series->description,
            'body_part' => $series->body_part,
            'instance_count' => (int) ($series->instances_count ?? 0),
            'created_at' => $series->created_at?->toDateTimeString(),
            'updated_at' => $series->updated_at?->toDateTimeString(),
        ];
    }
}
