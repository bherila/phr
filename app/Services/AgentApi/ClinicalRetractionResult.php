<?php

namespace App\Services\AgentApi;

use Illuminate\Database\Eloquent\Model;

final readonly class ClinicalRetractionResult
{
    public const string RETRACTED = 'retracted';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        public Model $record,
        public string $outcome,
        public string $version,
    ) {}
}
