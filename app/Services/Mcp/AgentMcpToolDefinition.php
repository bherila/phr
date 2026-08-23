<?php

namespace App\Services\Mcp;

use Closure;

final readonly class AgentMcpToolDefinition
{
    /**
     * @param  array{0: object|string, 1: string}|Closure  $handler
     * @param  string|null  $responseOperationId  The versioned REST operation this tool mirrors, whose
     *                                            OpenAPI response component becomes the MCP output schema.
     *                                            Null means the tool name is itself the operation id, which
     *                                            holds for every tool that is not one of the per-resource
     *                                            clinical families. Resolution never falls back to a
     *                                            permissive schema: an unmatched operation is a build error.
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array|Closure $handler,
        public bool $readOnly = true,
        public bool $destructive = false,
        public bool $idempotent = true,
        public ?string $responseOperationId = null,
    ) {}

    /** The REST operation whose response shape this tool returns verbatim. */
    public function responseOperationId(): string
    {
        return $this->responseOperationId ?? $this->name;
    }
}
