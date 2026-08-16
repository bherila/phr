<?php

namespace App\Support\Storage;

final class PhrSourceReconciliationSummary
{
    public int $sourceFiles = 0;

    public int $sourceBytes = 0;

    public int $sourceMatched = 0;

    public int $sourceUnmatched = 0;

    public int $documents = 0;

    public int $documentBytes = 0;

    public int $documentsMatched = 0;

    public int $documentsUnmatched = 0;

    public int $documentFailures = 0;

    public function clean(): bool
    {
        return $this->sourceUnmatched === 0
            && $this->documentsUnmatched === 0
            && $this->documentFailures === 0;
    }
}
