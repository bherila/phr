<?php

namespace App\Services\PHR\Import;

use App\DataTransferObjects\PHR\ImportReviewResult;
use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use App\Models\PhrPatient;
use App\Services\PHR\DataHub\PhrPatientArtifactWriteGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Shared atomic review workflow for browser and agent import proposals. */
final readonly class PhrImportReviewService
{
    public function __construct(
        private PhrPatientArtifactWriteGuard $artifactWriteGuard,
        private PhrImportJobDao $jobs,
        private PhrStructuredDataImporter $importer,
    ) {}

    /** @param array<string, mixed>|null $payload */
    public function accept(
        PhrPatient $patient,
        int $actorUserId,
        int $jobId,
        int $resultId,
        ?array $payload = null,
    ): ImportReviewResult {
        return $this->artifactWriteGuard->run((int) $patient->id, function (PhrPatient $lockedPatient) use ($actorUserId, $jobId, $payload, $resultId): ImportReviewResult {
            $target = $this->jobs->lockReviewTarget($lockedPatient, $jobId, $resultId);
            if ($target->result->status === 'imported') {
                return new ImportReviewResult($target->result, new PhrImportResult, ImportReviewResult::UNCHANGED);
            }
            if ($target->result->status !== 'pending_review') {
                throw new ConflictHttpException('This import proposal has already been reviewed.');
            }
            if (! PhrStructuredDataImporter::isPhrJobType($target->job->job_type)) {
                throw new UnprocessableEntityHttpException('The import job type is not supported.');
            }

            $import = $this->import(
                $lockedPatient,
                $actorUserId,
                $target->job,
                $payload ?? $target->result->getResultArray(),
            );
            $target->result->markImported();
            $this->finishJobWhenReviewed($target->job);

            return new ImportReviewResult($target->result->refresh(), $import, ImportReviewResult::ACCEPTED);
        });
    }

    public function reject(PhrPatient $patient, int $jobId, int $resultId): ImportReviewResult
    {
        return $this->artifactWriteGuard->run((int) $patient->id, function (PhrPatient $lockedPatient) use ($jobId, $resultId): ImportReviewResult {
            $target = $this->jobs->lockReviewTarget($lockedPatient, $jobId, $resultId);
            if ($target->result->status === 'skipped') {
                return new ImportReviewResult($target->result, new PhrImportResult, ImportReviewResult::UNCHANGED);
            }
            if ($target->result->status !== 'pending_review') {
                throw new ConflictHttpException('This import proposal has already been reviewed.');
            }

            $target->result->markSkipped();
            $this->finishJobWhenReviewed($target->job);

            return new ImportReviewResult($target->result->refresh(), new PhrImportResult, ImportReviewResult::REJECTED);
        });
    }

    /** @param array<string, mixed> $payload */
    private function import(
        PhrPatient $patient,
        int $actorUserId,
        GenAiImportJob $job,
        array $payload,
    ): PhrImportResult {
        $context = $job->getContextArray();
        if ($job->job_type === 'phr_document') {
            $sourceDocumentId = (int) ($context['document_id'] ?? 0);
            if ($sourceDocumentId > 0) {
                $document = PhrDocument::query()
                    ->where('patient_id', $patient->id)
                    ->findOrFail($sourceDocumentId);
                $document = $this->importer->updateDocumentFromGenAiResult($document, $job, $payload);
                $import = new PhrImportResult(updated: 1, documents: 1);
            } else {
                $document = $this->importer->storeGenAiDocument($patient, $actorUserId, $job, $payload);
                $import = new PhrImportResult(created: 1, documents: 1);
            }
            $import->merge($this->importer->importDocumentBundle($patient, $actorUserId, $document, $payload, [
                'import_source' => 'genai',
                'source' => 'genai_import',
                'genai_job_id' => $job->id,
            ]));

            return $import;
        }

        return $this->importer->importPayload($patient, $actorUserId, $job->job_type, $payload, [
            'import_source' => 'genai',
            'source' => 'genai_import',
            'genai_job_id' => $job->id,
            'source_document_id' => (int) ($context['document_id'] ?? 0) ?: null,
        ]);
    }

    private function finishJobWhenReviewed(GenAiImportJob $job): void
    {
        if (! $job->results()->where('status', 'pending_review')->exists()) {
            $job->markImported();
        }
    }
}
