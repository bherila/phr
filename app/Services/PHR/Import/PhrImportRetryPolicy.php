<?php

namespace App\Services\PHR\Import;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\Models\PhrDocument;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One eligibility policy for advertised and enforced import retries. */
final class PhrImportRetryPolicy
{
    public function canRetry(
        GenAiImportJob $job,
        ?PhrDocument $document,
        bool $hasReviewedResults,
    ): bool {
        return $document instanceof PhrDocument
            && ! $document->trashed()
            && ! $hasReviewedResults
            && $job->canRetry();
    }

    public function assertRetryable(
        GenAiImportJob $job,
        PhrDocument $document,
        bool $hasReviewedResults,
    ): void {
        if ($document->trashed()) {
            throw new NotFoundHttpException;
        }
        if (! $this->canRetry($job, $document, $hasReviewedResults)) {
            throw new ConflictHttpException('The import job cannot be retried.');
        }
    }
}
