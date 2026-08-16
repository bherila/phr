<?php

namespace App\Services\PHR\DataHub;

use App\Models\PhrPatientDeletion;

final class PhrPatientDeletionPresenter
{
    /** @return array<string, mixed> */
    public function payload(PhrPatientDeletion $deletion): array
    {
        return [
            'id' => $deletion->id,
            'patient_root_id' => $deletion->patient_root_id,
            'status' => $deletion->status,
            'record_counts' => $deletion->record_counts_json,
            'active_share_count' => $deletion->active_share_count,
            'artifact_count' => $deletion->artifact_count,
            'artifact_bytes' => $deletion->artifact_bytes,
            'failure_category' => $deletion->failure_category,
            'deleted_at' => $deletion->deleted_at->toIso8601String(),
            'completed_at' => $deletion->completed_at?->toIso8601String(),
        ];
    }
}
