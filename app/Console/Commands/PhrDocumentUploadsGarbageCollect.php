<?php

namespace App\Console\Commands;

use App\Services\PHR\Documents\SignedDocumentUploadService;
use Illuminate\Console\Command;

class PhrDocumentUploadsGarbageCollect extends Command
{
    protected $signature = 'phr:document-uploads:gc';

    protected $description = 'Expire signed document uploads that were never finalized and delete their staged bytes.';

    public function handle(SignedDocumentUploadService $uploads): int
    {
        $this->info(sprintf('PHR document upload gc complete: %d session(s) expired.', $uploads->expireStale()));

        return self::SUCCESS;
    }
}
