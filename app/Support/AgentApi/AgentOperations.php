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

    /**
     * The HTTP projection of the same declarations. Reconciliation's preview
     * dependency guides MCP discovery; REST accepts a digest obtained with a
     * separate credential, so apply retains its write-only HTTP requirement.
     */
    public function restRegistry(): OperationRegistry
    {
        $operations = [];
        foreach ($this->registry()->all() as $operation) {
            if ($operation->rest !== null) {
                $operations[] = $operation->id === 'reconciliations.apply'
                    ? $operation->with(requiresOperations: [])
                    : $operation;
            }
        }

        return (new OperationRegistry)->register(...$operations);
    }

    /**
     * The public capabilities inventory keeps its historical response shape.
     *
     * @return array<string, array{available: bool, scope: string|null, scopes?: list<string>}>
     */
    public function capabilities(): array
    {
        $operations = [];
        foreach ($this->availability()->implemented() as $operation) {
            if ($operation->rest === null) {
                continue;
            }
            $scopes = $operation->requirement->scopes;
            $operations[$operation->id] = [
                'available' => true,
                'scope' => $scopes === [] ? null : $scopes[array_key_last($scopes)],
            ];
            if (count($scopes) > 1) {
                $operations[$operation->id]['scopes'] = $scopes;
            }
        }
        // Legacy discovery alias for the POST /mcp operation.
        if (isset($operations['mcp.exchange'])) {
            $operations['mcp.connect'] = $operations['mcp.exchange'];
        }

        return $operations;
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

        return $operation->with(rest: self::restBinding($operation->id));
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
            routeName: $declared['route_name'],
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
