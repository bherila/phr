<?php

namespace App\Support\AgentApi;

/** JSON Schemas for the nested clinical data objects used by REST and MCP. */
final class AgentClinicalWriteSchemaCatalog
{
    /** @return array<string, mixed> */
    public static function data(string $resource): array
    {
        $ruleClass = AgentClinicalResourceCatalog::definition($resource)['write_rules'] ?? null;
        if (! is_string($ruleClass)) {
            throw new \InvalidArgumentException('Unsupported writable clinical resource.');
        }

        return $ruleClass::jsonSchema();
    }
}
