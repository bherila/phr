<?php

namespace App\Services\PHR\DICOM;

use App\Models\PhrDicomInstance;
use App\Models\PhrDicomStudy;
use Illuminate\Database\Eloquent\Builder;

/** Shared, metadata-only representation for browser and agent DICOM study lists. */
final class DicomStudyPresenter
{
    /** @param Builder<PhrDicomStudy> $query
     * @return Builder<PhrDicomStudy>
     */
    public function withSummaryMetrics(Builder $query): Builder
    {
        return $query
            ->select('phr_dicom_studies.*')
            ->selectSub(
                PhrDicomInstance::query()
                    ->join('phr_dicom_files', 'phr_dicom_files.id', '=', 'phr_dicom_instances.file_id')
                    ->selectRaw('COALESCE(SUM(phr_dicom_files.file_size_bytes), 0)')
                    ->whereColumn('phr_dicom_instances.study_id', 'phr_dicom_studies.id'),
                'file_size_bytes',
            )
            ->withCount(['series', 'instances']);
    }

    /** @return array<string, mixed> */
    public function payload(PhrDicomStudy $study): array
    {
        return [
            'id' => $study->id,
            'patient_id' => $study->patient_id,
            'upload_id' => $study->upload_id,
            'study_instance_uid' => $study->study_instance_uid,
            'study_date' => $study->study_date?->toDateString(),
            'study_time' => $study->study_time,
            'accession_number' => $study->accession_number,
            'description' => $study->description,
            'modalities' => $study->modalities,
            'series_count' => (int) ($study->series_count ?? 0),
            'instance_count' => (int) ($study->instances_count ?? 0),
            'file_size_bytes' => (int) ($study->getAttribute('file_size_bytes') ?? 0),
            'created_at' => $study->created_at?->toDateTimeString(),
            'updated_at' => $study->updated_at?->toDateTimeString(),
        ];
    }
}
