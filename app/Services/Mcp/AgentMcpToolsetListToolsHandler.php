<?php

namespace App\Services\Mcp;

use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * tools/list for a session that lists by toolset: the core tools and the
 * tools of each toolset the session enabled. The registry still holds every
 * authorized tool, so tools/call is unaffected. Cursors are the SDK's own
 * base64 offsets, taken over the filtered list.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final readonly class AgentMcpToolsetListToolsHandler implements RequestHandlerInterface
{
    public function __construct(
        private RegistryInterface $registry,
        private int $pageSize,
    ) {}

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    /** @return Response<ListToolsResult> */
    public function handle(Request $request, SessionInterface $session): Response
    {
        assert($request instanceof ListToolsRequest);

        $enabled = AgentMcpToolsets::enabled($session);
        $tools = array_values(array_filter(
            $this->registry->getTools()->references,
            static function (Tool $tool) use ($enabled): bool {
                $toolset = AgentMcpToolsets::toolsetFor($tool->name);

                return $toolset === null || in_array($toolset, $enabled, true);
            },
        ));

        $offset = 0;
        if ($request->cursor !== null) {
            $decoded = base64_decode($request->cursor, true);
            if ($decoded === false || ! ctype_digit($decoded) || (int) $decoded > count($tools)) {
                throw new InvalidCursorException($request->cursor);
            }
            $offset = (int) $decoded;
        }
        $next = $offset + $this->pageSize < count($tools) ? base64_encode((string) ($offset + $this->pageSize)) : null;

        return new Response(
            $request->getId(),
            new ListToolsResult(array_slice($tools, $offset, $this->pageSize), $next),
        );
    }
}
