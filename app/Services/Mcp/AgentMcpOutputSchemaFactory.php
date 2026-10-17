<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;

/**
 * Resolves each tool's output schema from the REST operation it mirrors.
 *
 * MCP handlers return the REST envelope verbatim, so the published OpenAPI
 * response component is already the correct description of what a tool emits.
 * There is deliberately no fallback: a tool whose operation declares no
 * response component fails when the server is built rather than advertising
 * an unconstrained object.
 *
 * Prose descriptions are dropped: MCP has no cross-tool references, so every
 * tool repeats its shared definitions, and tools/list must stay small enough
 * for clients to accept. The OpenAPI document keeps the descriptions.
 */
final class AgentMcpOutputSchemaFactory
{
    /** @return array<string, mixed> */
    public function for(ToolDefinition $definition): array
    {
        return self::withoutDescriptions(AgentApiResponseSchemaCatalog::forOperation($definition->responseOperationId()));
    }

    /**
     * Drops the description keyword from a schema and its subschemas, never a
     * property or definition that happens to be named "description".
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    public static function withoutDescriptions(array $schema, bool $namesSubschemas = false): array
    {
        if (! $namesSubschemas && is_string($schema['description'] ?? null)) {
            unset($schema['description']);
        }
        foreach ($schema as $key => $value) {
            // Literal values are data, not schemas.
            $literal = ! $namesSubschemas && in_array($key, ['const', 'default', 'enum', 'examples'], true);
            if (is_array($value) && ! $literal) {
                $schema[$key] = self::withoutDescriptions(
                    $value,
                    ! $namesSubschemas && in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true),
                );
            }
        }

        return $schema;
    }
}
