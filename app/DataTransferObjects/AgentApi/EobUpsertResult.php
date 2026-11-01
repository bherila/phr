<?php

namespace App\DataTransferObjects\AgentApi;

use App\Models\PhrEob;
use App\Models\PhrEobLine;
use Illuminate\Support\Collection;

final readonly class EobUpsertResult
{
    /**
     * @param  array{by: string, import_source: string}|null  $match  how an existing claim was recognised, when the write did not create one
     * @param  Collection<int, PhrEobLine>  $lines
     * @param  list<array<string, mixed>>  $fills  one entry per line that carried a fill
     */
    public function __construct(
        public PhrEob $eob,
        public string $outcome,
        public string $version,
        public ?array $match,
        public Collection $lines,
        public array $fills,
    ) {}
}
