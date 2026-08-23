<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Bherila\McpLaravelBridge\Mcp\OriginalShapeSchemaValidator;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
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
        $schemaIds = [];
        foreach ($definitions as $definition) {
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
            ->setInstructions(implode(' ', [
                'Authenticate with OAuth Authorization Code plus S256 PKCE using the server metadata at /.well-known/oauth-authorization-server; request mcp:use, identity:read, patients:read, and only the narrow clinical, document, or import scopes needed for the task.',
                'After connection, call identity.get, then patients.list to enumerate the patients available to the logged-in account. Never guess a patient id; confirm the selected patient with patients.get before reading or writing.',
                'Use bounded list operations with cursors. Read existing records before writing. Every clinical upsert needs a deterministic external_id. The resource-specific update tool requires clinical:read as well as clinical:write: read the target record first, then supply its ID and current opaque version. It patches that patient-scoped row without changing import identity, conflicts if the record changed, and reopens human review on every effective change.',
                'Keep interpreted source data distinct from evidence: upsert or update only normalized records supported by the clinical schemas. A source document is optional when the user does not want the local source retained, but records without retained evidence must still carry stable import provenance and remain pending review. Preserve source_document_id unless changing it is explicitly intended. Use pending_review unless the user has explicitly approved the proposed record, and use imports.review when working through a staged extraction proposal.',
                'Request document download access only when file contents are explicitly needed.',
            ]))
            ->setPaginationLimit(100)
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

        foreach ($definitions as $definition) {
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
}
