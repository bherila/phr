<?php

namespace App\Services\Mcp;

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * The SDK's initialize, announcing the configured capabilities unchanged.
 *
 * The SDK drops every listChanged flag from a handshake-era initialize,
 * because it sends list changes through the modern era's subscriptions.
 * This server sends notifications/tools/list_changed itself, on the event
 * stream that answers toolsets.enable and toolsets.disable, so it says so:
 * a client may act on the notification only when the server announces it.
 *
 * @implements RequestHandlerInterface<InitializeResult>
 */
final readonly class AgentMcpListChangedInitializeHandler implements RequestHandlerInterface
{
    public function __construct(
        private InitializeHandler $inner,
        private ServerCapabilities $capabilities,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    /** @return Response<InitializeResult> */
    public function handle(Request $request, SessionInterface $session): Response
    {
        $response = $this->inner->handle($request, $session);
        $result = $response->result;

        return new Response($response->getId(), new InitializeResult(
            $this->capabilities,
            $result->serverInfo,
            $result->instructions,
            $result->meta,
            $result->protocolVersion,
        ));
    }
}
