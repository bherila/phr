<?php

namespace App\DataTransferObjects\AgentApi;

use Illuminate\Database\Eloquent\Model;

final readonly class AgentAppendResult
{
    public const string CREATED = 'created';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        public Model $record,
        public string $outcome,
    ) {}
}
