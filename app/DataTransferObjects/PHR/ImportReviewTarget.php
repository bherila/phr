<?php

namespace App\DataTransferObjects\PHR;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Models\GenAiImportResult;

/** A patient-scoped job and proposal locked for one review mutation. */
final readonly class ImportReviewTarget
{
    public function __construct(
        public GenAiImportJob $job,
        public GenAiImportResult $result,
    ) {}
}
