<?php

namespace App\Services\PHR\Import;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;

/**
 * One shape for an AI import job and its proposed results, shared by the agent
 * API and the browser's Imports page. Expects the job's sourceDocument and its
 * results / pending_results counts loaded.
 */
final readonly class PhrImportJobPresenter
{
    public function __construct(private PhrImportRetryPolicy $retryPolicy) {}

    /** @return array<string, mixed> */
    public function job(GenAiImportJob $job): array
    {
        return [
            'id' => (int) $job->id,
            'document_id' => $job->sourceDocument?->id,
            'job_type' => $job->job_type,
            'status' => $job->status,
            'retry_count' => $job->retry_count,
            'can_retry' => $this->retryPolicy->canRetry(
                $job,
                $job->sourceDocument,
                (int) ($job->results_count ?? 0) !== (int) ($job->pending_results_count ?? 0),
            ),
            'result_count' => (int) ($job->results_count ?? 0),
            'pending_result_count' => (int) ($job->pending_results_count ?? 0),
            'processing_tier' => $job->processing_tier,
            'scheduled_for' => $job->scheduled_for?->toDateString(),
            'parsed_at' => $job->parsed_at?->toIso8601String(),
            'failure_code' => $job->status === 'failed' ? 'processing_failed' : null,
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{id: int, status: string} */
    public function resultReceipt(GenAiImportResult $result): array
    {
        return ['id' => (int) $result->id, 'status' => (string) $result->status];
    }

    /** @return array<string, mixed> */
    public function result(GenAiImportResult $result): array
    {
        $data = $result->getResultArray();

        return [
            ...$this->resultReceipt($result),
            'result_index' => $result->result_index,
            'produced_by' => $result->produced_by,
            'data' => $data === [] ? (object) [] : $data,
            'imported_at' => $result->imported_at?->toIso8601String(),
            'created_at' => $result->created_at?->toIso8601String(),
            'updated_at' => $result->updated_at?->toIso8601String(),
        ];
    }
}
