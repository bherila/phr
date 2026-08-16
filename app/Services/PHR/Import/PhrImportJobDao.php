<?php

namespace App\Services\PHR\Import;

use App\DataTransferObjects\PHR\ImportReviewTarget;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Typed data-access boundary for patient-scoped import jobs and proposals. */
final class PhrImportJobDao
{
    /** @return Builder<GenAiImportJob> */
    public function forPatient(PhrPatient $patient): Builder
    {
        return GenAiImportJob::query()
            ->where('context_json->patient_id', $patient->id)
            ->whereHas(
                'sourceDocument',
                fn (Builder $query): Builder => $query->where('patient_id', $patient->id),
            )
            ->whereDoesntHave(
                'sourceDocument',
                fn (Builder $query): Builder => $query->where('patient_id', '!=', $patient->id),
            );
    }

    public function find(PhrPatient $patient, int $jobId): GenAiImportJob
    {
        return $this->forPatient($patient)->findOrFail($jobId);
    }

    public function findById(int $jobId): GenAiImportJob
    {
        return GenAiImportJob::query()->findOrFail($jobId);
    }

    public function findForDocument(PhrPatient $patient, PhrDocument $document): ?GenAiImportJob
    {
        if ($document->genai_job_id === null) {
            return null;
        }

        return $this->forPatient($patient)->find($document->genai_job_id);
    }

    public function result(GenAiImportJob $job, int $resultId): GenAiImportResult
    {
        $result = $job->results()->whereKey($resultId)->first();
        if (! $result instanceof GenAiImportResult) {
            throw (new ModelNotFoundException)->setModel(GenAiImportResult::class, [$resultId]);
        }

        return $result;
    }

    /**
     * Resolve and lock both sides of a review mutation inside the caller's
     * patient-row transaction. Context is checked independently of the
     * optional document link because CLI-created legacy jobs have no link.
     */
    public function lockReviewTarget(PhrPatient $patient, int $jobId, int $resultId): ImportReviewTarget
    {
        $job = GenAiImportJob::query()->whereKey($jobId)->lockForUpdate()->first();
        if (! $job instanceof GenAiImportJob
            || (int) ($job->getContextArray()['patient_id'] ?? 0) !== (int) $patient->id) {
            $this->notFound(GenAiImportJob::class, $jobId);
        }

        if ($job->sourceDocument()->where('patient_id', '!=', $patient->id)->exists()) {
            $this->notFound(GenAiImportJob::class, $jobId);
        }

        $result = $job->results()->whereKey($resultId)->lockForUpdate()->first();
        if (! $result instanceof GenAiImportResult) {
            $this->notFound(GenAiImportResult::class, $resultId);
        }

        return new ImportReviewTarget($job, $result);
    }

    /** @param class-string<GenAiImportJob|GenAiImportResult> $model */
    private function notFound(string $model, int $id): never
    {
        throw (new ModelNotFoundException)->setModel($model, [$id]);
    }
}
