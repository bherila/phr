<?php

namespace App\Support\AgentApi;

use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use Bherila\GenAiLaravel\Mcp\GenAiMcpToolCatalog;
use Bherila\GenAiLaravel\Mcp\Tools\GenAiMcpTools;
use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\ConfigDeploymentFlags;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Capabilities\SchemaRef;
use Bherila\McpLaravelBridge\Capabilities\WriteSafety;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;

/**
 * Every agent operation in one capability registry: the PHR tools, the
 * external GenAI mailbox tools, and the REST operations no tool fronts. One
 * availability evaluation then decides what each caller sees over MCP and
 * what the identity endpoint reports as withheld.
 */
final class AgentOperations
{
    private ?OperationRegistry $registry = null;

    public function __construct(
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpReadTools $reads,
        private readonly AgentMcpWriteTools $writes,
        private readonly GenAiMcpToolCatalog $genAiCatalog,
        private readonly GenAiMcpTools $genAiTools,
    ) {}

    public function registry(): OperationRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }
        $documented = AgentApiResponseSchemaCatalog::operations();
        $operations = [];
        foreach ($this->catalog->operations($this->reads, $this->writes) as $operation) {
            $operations[$operation->id] = self::withRestBinding($operation, $documented[$operation->id] ?? null);
        }
        foreach ($this->genAiCatalog->definitions($this->genAiTools) as $definition) {
            $operations[$definition->name] = $this->genAiOperation($definition);
        }
        foreach ($documented as $id => $declared) {
            $operations[$id] ??= self::restOperation($id, $declared);
        }

        return $this->registry = (new OperationRegistry)->register(...array_values($operations));
    }

    public function availability(): Availability
    {
        return new Availability($this->registry(), new ConfigDeploymentFlags([]));
    }

    private function genAiOperation(ToolDefinition $definition): Operation
    {
        return new Operation(
            id: $definition->name,
            title: $definition->title,
            description: $definition->description,
            effect: $definition->readOnly ? Effect::Read : Effect::LocalWrite,
            requirement: new Requirement([$this->genAiCatalog->requiredScope($definition)]),
            idempotent: $definition->idempotent,
            safety: $definition->readOnly ? new WriteSafety : new WriteSafety(note: 'Acts on the request this connection leased.'),
            mcp: new McpBinding(handler: $definition->handler),
        );
    }

    /** @param  array{method: string, path: string, scopes: list<string>|null, summary: string, description: string}|null  $declared */
    private static function withRestBinding(Operation $operation, ?array $declared): Operation
    {
        if ($declared === null) {
            return $operation;
        }

        return new Operation(
            id: $operation->id,
            title: $operation->title,
            description: $operation->description,
            effect: $operation->effect,
            requirement: $operation->requirement,
            idempotent: $operation->idempotent,
            safety: $operation->safety,
            rest: self::restBinding($operation->id),
            mcp: $operation->mcp,
            input: $operation->input,
            output: $operation->output,
            requiresOperations: $operation->requiresOperations,
        );
    }

    /** @param  array{method: string, path: string, scopes: list<string>|null, summary: string, description: string}  $declared */
    private static function restOperation(string $id, array $declared): Operation
    {
        $reads = in_array($declared['method'], ['GET', 'HEAD'], true);

        return new Operation(
            id: $id,
            title: $declared['summary'] !== '' ? $declared['summary'] : $id,
            description: $declared['description'] !== '' ? $declared['description'] : ($declared['summary'] !== '' ? $declared['summary'] : $id),
            effect: $reads ? Effect::Read : Effect::LocalWrite,
            requirement: AgentMcpToolCatalog::requirementFor($id),
            safety: $reads ? new WriteSafety : new WriteSafety(note: 'As declared by the OpenAPI operation.'),
            rest: self::restBinding($id),
        );
    }

    /**
     * The REST binding and everything the contract says about it, from
     * {@see AgentRestDocumentation}; the document is generated from these.
     */
    private static function restBinding(string $id): RestBinding
    {
        $declared = AgentRestDocumentation::operations()[$id];
        $schema = static fn (string|array $schema): array|SchemaRef => is_string($schema) ? SchemaRef::openApi($schema) : $schema;

        return new RestBinding(
            method: $declared['method'],
            path: $declared['path'],
            successStatuses: array_keys($declared['success']),
            requestContentTypes: $declared['request_types'] ?? ['application/json'],
            summary: $declared['summary'],
            description: $declared['description'],
            parameters: $declared['parameters'],
            requestSchema: isset($declared['request']) ? $schema($declared['request']) : false,
            responseSchema: isset($declared['response']) ? $schema($declared['response']) : null,
            responseDescriptions: $declared['success'],
            responses: $declared['responses'] ?? [],
            requestBodyRequired: $declared['request_required'] ?? true,
        );
    }
}
