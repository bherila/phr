<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;

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
    public function for(AgentMcpToolDefinition $definition): array
    {
        return AgentApiResponseSchemaCatalog::forOperation($definition->responseOperationId());
    }
}
