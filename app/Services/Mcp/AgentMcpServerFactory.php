<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Bherila\McpLaravelBridge\Mcp\OriginalShapeSchemaValidator;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Bherila\McpLaravelBridge\Mcp\ValidatedCallToolHandler;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AgentMcpServerFactory
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpReadTools $reads,
        private readonly AgentMcpWriteTools $writes,
        private readonly AgentMcpPrompts $prompts,
        private readonly AgentMcpInputSchemaFactory $schemas,
        private readonly AgentMcpOutputSchemaFactory $outputSchemas,
        private readonly RequestArguments $requestArguments,
    ) {}

    public function make(Request $request): Server
    {
        $logger = new NullLogger;
        $driftLogger = app(LoggerInterface::class);
        $registry = new Registry(logger: $logger);
        $referenceHandler = new ReferenceHandler(app());
        $definitions = $this->catalog->definitions($this->reads, $this->writes);
        $exposedDefinitions = array_values(array_filter(
            $definitions,
            fn (ToolDefinition $definition): bool => $this->canExpose($request, $definition),
        ));
        $exposedToolNames = array_fill_keys(array_map(
            static fn (ToolDefinition $definition): string => $definition->name,
            $exposedDefinitions,
        ), true);
        $schemaIds = [];
        foreach ($exposedDefinitions as $definition) {
            $schemaIds[$definition->name] = AgentApiResponseSchemaCatalog::operationComponent(
                $definition->responseOperationId(),
            );
        }
        $builder = Server::builder()
            ->setServerInfo(
                name: 'PHR Agent API',
                version: 'v1',
                description: 'Read and safely update authorized personal health records through the versioned PHR REST API.',
                websiteUrl: url('/'),
            )
            ->setInstructions($this->instructions($exposedToolNames));

        if ($this->hasTools($exposedToolNames, [
            'identity.get',
            'patients.list',
            'patients.get',
            'records.search',
            'office_visits.update',
        ])) {
            $builder->addPrompt(
                handler: [$this->prompts, 'safelyUpdateClinicalRecord'],
                name: 'safely-update-clinical-record',
                title: 'Safely update a clinical record',
                description: 'Discover the exact patient and current record before a version-checked clinical update.',
            );
        }
        if ($this->hasTools($exposedToolNames, [
            'identity.get',
            'patients.list',
            'patients.get',
            'records.search',
            'imports.list',
            'imports.get',
            'imports.review',
        ])) {
            $builder->addPrompt(
                handler: [$this->prompts, 'reviewImportProposal'],
                name: 'review-import-proposal',
                title: 'Review an import proposal',
                description: 'Inventory evidence and existing records, prevent duplicates, and wait for approval before import writes.',
            );
        }

        $builder->setPaginationLimit(100)
            // Sessions contain protocol negotiation state, never tool arguments or
            // results. A token-derived cache namespace prevents possession of a
            // session UUID from crossing OAuth-token boundaries.
            ->setSession(new Psr16SessionStore(
                cache: $this->cache,
                prefix: CredentialSessionNamespace::prefix($request, 'phr_mcp_'),
                ttl: (int) config('agent_api.mcp_session_ttl_seconds', 1800),
            ))
            // The SDK's debug logger includes tool arguments and results. A null
            // logger is mandatory here because both can contain health records.
            ->setLogger($logger)
            ->setContainer(app())
            ->setRegistry($registry)
            ->setReferenceHandler($referenceHandler)
            // Register first so the protocol selects the shape-aware validator
            // before the SDK's default CallToolHandler.
            ->addRequestHandler(new ValidatedCallToolHandler(
                new CallToolHandler(
                    $registry,
                    $referenceHandler,
                    $logger,
                    new OriginalShapeSchemaValidator($logger, $this->requestArguments),
                ),
                $registry,
                // A plain validator: the shape-aware subclass consumes the queued
                // wire arguments, which belong to input validation only. Null
                // logger because the SDK logs data and schema on internal errors.
                new SchemaValidator($logger),
                $schemaIds,
                $driftLogger,
                'The PHR API returned a response that failed its output contract.',
            ))
            ->setLazyLoading(false);

        foreach ($exposedDefinitions as $definition) {
            $builder->addTool(
                handler: $definition->handler,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                annotations: new ToolAnnotations(
                    readOnlyHint: $definition->readOnly,
                    destructiveHint: $definition->destructive,
                    idempotentHint: $definition->idempotent,
                    openWorldHint: false,
                ),
                inputSchema: $this->schemas->for($definition),
                outputSchema: $this->outputSchemas->for($definition),
            );
        }

        return $builder->build();
    }

    /**
     * @param  array<string, true>  $available
     * @param  list<string>  $required
     */
    private function hasTools(array $available, array $required): bool
    {
        return array_diff($required, array_keys($available)) === [];
    }

    private function canExpose(Request $request, ToolDefinition $definition): bool
    {
        if ($definition->responseOperationId() === 'capabilities.get') {
            return true;
        }

        $user = $request->user('api');
        foreach (AgentApiResponseSchemaCatalog::scopesForOperation($definition->responseOperationId()) as $scope) {
            if (! $user?->tokenCan($scope)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, true> $available */
    private function instructions(array $available): string
    {
        $hasPatientContext = $this->hasTools($available, ['identity.get', 'patients.list', 'patients.get']);
        $hasClinicalUpdate = $this->hasTools($available, ['records.search', 'office_visits.update']);
        $hasImportReview = $this->hasTools($available, ['records.search', 'imports.list', 'imports.get', 'imports.review']);

        if ($hasPatientContext && ($hasClinicalUpdate || $hasImportReview)) {
            $base = 'First call identity.get, then patients.list; select only a patient ID returned by PHR, never guess one, and confirm it with patients.get before reading or writing. Read existing patient-scoped records before every write. Prevent duplicates: every clinical upsert needs stable provenance and a deterministic external_id. Before an update, read the target record and supply its returned ID and current opaque version. Keep changes pending_review unless the user explicitly approves the clinical facts.';
        } elseif ($hasPatientContext) {
            $base = 'First call identity.get, then patients.list; select only a patient ID returned by PHR, never guess one, and confirm it with patients.get. Use only operations currently exposed in tools/list; missing tools are not authorized for this connection.';
        } else {
            $base = 'Use only operations currently exposed in tools/list; missing tools are not authorized for this connection. Never guess a patient or record ID.';
        }

        $details = [];
        if ($hasClinicalUpdate) {
            $details[] = 'The resource-specific update tools require clinical:read and clinical:write, preserve import identity unless explicitly changed, conflict if the record changed, and reopen human review on every effective change.';
        }
        if ($hasImportReview) {
            $details[] = 'Keep interpreted source data distinct from evidence, inspect staged proposals and existing records, and obtain explicit user approval before imports.review.';
        }
        if (isset($available['documents.download_access.create'])) {
            $details[] = 'Request document download access only when file contents are explicitly needed.';
        }

        $promptGuidance = [];
        if ($hasPatientContext && $hasClinicalUpdate) {
            $promptGuidance[] = 'safely-update-clinical-record';
        }
        if ($hasPatientContext && $hasImportReview) {
            $promptGuidance[] = 'review-import-proposal';
        }
        if ($promptGuidance !== []) {
            $details[] = 'Use the '.implode(' and ', $promptGuidance).' prompts for complete guided workflows when the client exposes MCP prompts.';
        }

        $details[] = 'Authenticate with OAuth Authorization Code plus S256 PKCE and request only the narrow identity:read, patients:read, clinical, document, or import scopes needed for the task.';

        return $base.' '.implode(' ', $details);
    }
}
