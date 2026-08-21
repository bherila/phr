<?php

namespace App\Services\Mcp;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\NullLogger;

final class AgentMcpServerFactory
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpReadTools $reads,
        private readonly AgentMcpWriteTools $writes,
        private readonly AgentMcpInputSchemaFactory $schemas,
        private readonly AgentMcpRequestArguments $requestArguments,
    ) {}

    public function make(Request $request): Server
    {
        $logger = new NullLogger;
        $registry = new Registry(logger: $logger);
        $referenceHandler = new ReferenceHandler(app());
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
                'Use bounded list operations with cursors. Read existing records before writing. Every clinical upsert needs a deterministic external_id. Use the resource-specific update tool only after reading the target record ID and its current opaque version; it patches that patient-scoped row without changing import identity, and conflicts if the record changed.',
                'Keep interpreted source data distinct from evidence: upsert or update only normalized records supported by the clinical schemas. Upload source evidence only when the user wants it retained; local extraction sources do not need document upload. Preserve source_document_id unless changing it is explicitly intended. Use pending_review unless the user has explicitly approved the proposed record, and use imports.review when working through a staged extraction proposal.',
                'Request document download access only when file contents are explicitly needed.',
            ]))
            ->setPaginationLimit(100)
            // Sessions contain protocol negotiation state, never tool arguments or
            // results. A token-derived cache namespace prevents possession of a
            // session UUID from crossing OAuth-token boundaries.
            ->setSession(new Psr16SessionStore(
                cache: $this->cache,
                prefix: 'phr_mcp_'.hash('sha256', $this->tokenIdentity($request)).'_',
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
            ->addRequestHandler(new CallToolHandler(
                $registry,
                $referenceHandler,
                $logger,
                new AgentMcpSchemaValidator($logger, $this->requestArguments),
            ))
            ->setLazyLoading(false);

        foreach ($this->catalog->definitions($this->reads, $this->writes) as $definition) {
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
                outputSchema: ['type' => 'object', 'additionalProperties' => true],
            );
        }

        return $builder->build();
    }

    private function tokenIdentity(Request $request): string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $token = $request->user('api')?->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $tokenId = $attributes['oauth_access_token_id'] ?? null;

        // OPTIONS is intentionally unauthenticated so browser preflight works;
        // the transport returns before it accesses any session state.
        if (is_string($tokenId) && $tokenId !== '') {
            return $tokenId;
        }
        if ($token instanceof AccessToken) {
            // Passport::actingAs() uses an in-memory AccessToken without an id.
            // Object identity keeps feature tests faithful to the production
            // token-isolation boundary without weakening the bearer-token path.
            return 'transient-'.spl_object_id($token);
        }

        return 'preflight';
    }
}
