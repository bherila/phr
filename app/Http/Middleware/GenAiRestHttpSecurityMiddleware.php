<?php

namespace App\Http\Middleware;

use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the shared MCP HTTP protections with REST-specific payload limits.
 *
 * The MCP control plane stays tightly bounded. The GenAI REST data plane must
 * also accept its documented completion envelope and stream its much larger
 * private source attachment without the shared response cap replacing it.
 */
final readonly class GenAiRestHttpSecurityMiddleware
{
    private McpHttpSecurityMiddleware $security;

    public function __construct(McpHttpPolicy $basePolicy, ExceptionHandler $exceptions)
    {
        $this->security = new McpHttpSecurityMiddleware(new McpHttpPolicy(
            allowedOrigins: $basePolicy->allowedOrigins,
            allowedHosts: $basePolicy->allowedHosts,
            // The package limit covers the canonical response object. Leave a
            // small, bounded allowance for the lease token, executor metadata,
            // and outer REST envelope that carry that response.
            maxRequestBodyBytes: (int) config('genai.mcp.limits.max_completion_bytes', 1_048_576) + 16_384,
            maxResponseBodyBytes: (int) config('genai.mcp.limits.max_attachment_bytes', 104_857_600),
            allowedMethods: ['GET', 'HEAD', 'POST'],
            allowedHeaders: [...$basePolicy->allowedHeaders, 'Idempotency-Key'],
            exposedHeaders: $basePolicy->exposedHeaders,
        ), $exceptions);
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->security->handle($request, $next);
    }
}
