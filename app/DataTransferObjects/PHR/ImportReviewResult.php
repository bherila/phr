<?php

namespace App\DataTransferObjects\PHR;

use App\GenAiProcessor\Models\GenAiImportResult;
use App\Services\PHR\Import\PhrImportResult;

final readonly class ImportReviewResult
{
    public const string ACCEPTED = 'accepted';

    public const string REJECTED = 'rejected';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        public GenAiImportResult $result,
        public PhrImportResult $import,
        public string $outcome,
    ) {}
}
