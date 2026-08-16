<?php

namespace App\Services\AgentApi\Client;

final class AgentApiQuery
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $values
     * @return array<string, scalar|list<scalar>>
     */
    public static function present(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }
}
