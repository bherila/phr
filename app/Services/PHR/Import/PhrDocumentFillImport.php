<?php

namespace App\Services\PHR\Import;

/** What writing a document's fills did, and the lines they cover. */
final readonly class PhrDocumentFillImport
{
    /**
     * @param  list<array{printed: string, ndc: string, names: list<string>}>  $lines
     */
    public function __construct(
        public PhrImportResult $result,
        public array $lines,
    ) {}
}
