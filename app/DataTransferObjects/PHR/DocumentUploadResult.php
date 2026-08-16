<?php

namespace App\DataTransferObjects\PHR;

use App\Models\PhrDocument;

final readonly class DocumentUploadResult
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        public PhrDocument $document,
        public string $outcome,
    ) {}
}
