<?php

namespace App\Services\Mcp;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\NullLogger;

final class AgentMcpServerFactory
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpReadTools $tools,
        private readonly AgentMcpInputSchemaFactory $schemas,
    ) {}

    public function make(Request $request): Server
    {
        $builder = Server::builder()
            ->setServerInfo(
                name: 'PHR Agent API',
                version: 'v1',
                description: 'Read authorized personal health records through the versioned PHR REST API.',
                websiteUrl: url('/'),
            )
            ->setInstructions('Use list operations with bounded limits and cursors. Request document download access only when file contents are explicitly needed.')
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
            ->setLogger(new NullLogger)
            ->setContainer(app())
            ->setLazyLoading(false);

        $annotations = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            openWorldHint: false,
        );
        foreach ($this->catalog->definitions($this->tools) as $definition) {
            $builder->addTool(
                handler: $definition->handler,
                name: $definition->name,
                title: $definition->title,
                description: $definition->description,
                annotations: $annotations,
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
