<?php

namespace App\DataTransferObjects\PHR;

use App\GenAiProcessor\Models\GenAiImportJob;

final readonly class ImportJobMutationResult
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public const string RETRIED = 'retried';

    public function __construct(
        public GenAiImportJob $job,
        public int $documentId,
        public string $outcome,
    ) {}
}
