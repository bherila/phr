<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Log;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Throwable;

/**
 * Validates every successful tool result against its advertised output schema.
 *
 * An output schema a client can trust has to be enforced, not merely published.
 * The SDK's CallToolHandler is final, so this decorates it: the inner handler
 * runs the tool, and structured content only leaves the process once it matches
 * the REST response component the tool declares.
 *
 * On a mismatch the caller gets a generic failure. The validation errors carry
 * JSON pointers, and a pointer into a map keyed by external ID -- as the resolve
 * response is -- contains a caller-chosen identifier, so neither the errors nor
 * the data are logged. Only the tool name, the schema id, and the failing
 * keywords are recorded, which is enough to find the drift in a test run.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final readonly class AgentMcpValidatedCallToolHandler implements RequestHandlerInterface
{
    /** @param array<string, string> $schemaIds Tool name to the REST response component it returns. */
    public function __construct(
        private CallToolHandler $inner,
        private RegistryInterface $registry,
        private SchemaValidator $validator,
        private array $schemaIds,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $response = $this->inner->handle($request, $session);

        if (! $request instanceof CallToolRequest || ! $response instanceof Response) {
            return $response;
        }
        $result = $response->result;
        // A tool that already failed has no structured content to guarantee.
        if ($result->isError || $result->structuredContent === null) {
            return $response;
        }

        try {
            $errors = $this->validator->validateAgainstJsonSchema(
                $result->structuredContent,
                $this->registry->getTool($request->name)->tool->outputSchema,
            );
            $keywords = array_map(static fn (array $error): string => $error['keyword'], $errors);
        } catch (Throwable) {
            // A tool with no resolvable schema is drift too, never a free pass.
            $keywords = ['validation-unavailable'];
        }

        if ($keywords === []) {
            return $response;
        }

        Log::warning('Agent MCP tool result did not match its output schema.', [
            'tool' => $request->name,
            'schema' => $this->schemaIds[$request->name] ?? 'unknown',
            'keywords' => array_values(array_unique($keywords)),
        ]);

        return Error::forInternalError('The PHR API returned a response that failed its output contract.', $request->getId());
    }
}
