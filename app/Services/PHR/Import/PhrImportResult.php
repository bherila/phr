<?php

namespace App\Services\PHR\Import;

class PhrImportResult
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $documents = 0,
        public int $skipped = 0,
        public array $warnings = [],
    ) {}

    public function addCreated(int $count = 1): void
    {
        $this->created += $count;
    }

    public function addUpdated(int $count = 1): void
    {
        $this->updated += $count;
    }

    public function addDocument(int $count = 1): void
    {
        $this->documents += $count;
    }

    public function addSkipped(int $count = 1): void
    {
        $this->skipped += $count;
    }

    public function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function merge(self $other): void
    {
        $this->created += $other->created;
        $this->updated += $other->updated;
        $this->documents += $other->documents;
        $this->skipped += $other->skipped;
        $this->warnings = [...$this->warnings, ...$other->warnings];
    }

    /**
     * @return array{created: int, updated: int, documents: int, skipped: int, warnings: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'documents' => $this->documents,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
        ];
    }
}
