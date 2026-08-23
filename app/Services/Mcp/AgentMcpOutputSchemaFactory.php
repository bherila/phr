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
 */
final class AgentMcpOutputSchemaFactory
{
    /** @return array<string, mixed> */
    public function for(ToolDefinition $definition): array
    {
        return AgentApiResponseSchemaCatalog::forOperation($definition->responseOperationId());
    }
}
