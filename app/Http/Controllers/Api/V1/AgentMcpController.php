<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mcp\AgentMcpServerFactory;
use Bherila\McpLaravelBridge\Http\SdkMiddlewareProfile;
use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Illuminate\Http\Request;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\DiscoverRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Server\Wire\InboundClassifier;
use Symfony\Component\HttpFoundation\Response;

final class AgentMcpController extends Controller
{
    public function __invoke(
        Request $request,
        AgentMcpServerFactory $servers,
        StreamableHttpResponder $responder,
    ): Response {
        $supported = ProtocolVersion::handshakeVersions();
        $header = (string) $request->header('Mcp-Protocol-Version', '');
        if ($header !== '' && ! in_array(ProtocolVersion::tryFrom($header), $supported, true)) {
            return response()->json(Error::forUnsupportedProtocolVersion($header, $supported), 400);
        }

        if ($request->isMethod('POST') && $request->input('method') === 'server/discover') {
            $body = $request->getContent();
            if (strlen($body) > (int) config('agent_api.mcp_max_body_bytes', 262_144)) {
                return response()->json(Error::forInvalidRequest('Request body exceeds the maximum allowed size.'), 413);
            }
            $classification = (new InboundClassifier)->classify('POST', $body, ['MCP-Protocol-Version' => $header]);
            if ($classification->isRejected()) {
                return response()->json($classification->error, $classification->httpStatus);
            }
            if ($classification->modern) {
                return response()->json(Error::forUnsupportedProtocolVersion($classification->claimedVersion ?? '', $supported), 400);
            }
            $messages = MessageFactory::make(additional: [DiscoverRequest::class])->create($body);
            $message = $messages[0] ?? null;
            if (count($messages) !== 1 || ! $message instanceof DiscoverRequest) {
                return response()->json(Error::forInvalidRequest('Expected a server/discover request with a valid id.'), 400);
            }

            $configuration = $servers->discoveryConfiguration($request);
            $version = $configuration->protocolVersion ?? ProtocolVersion::latestHandshake();
            $result = new InitializeResult(
                $configuration->capabilities,
                $configuration->serverInfo,
                $configuration->instructions,
                protocolVersion: $version,
            );

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $message->getId(),
                'result' => [
                    ...$result->jsonSerialize(),
                    'supportedVersions' => array_map(static fn (ProtocolVersion $version): string => $version->value, $supported),
                ],
            ]);
        }

        return $responder->run(
            request: $request,
            server: $servers->make($request),
            middleware: SdkMiddlewareProfile::forHardenedLaravelEdge(),
            maxBodyBytes: (int) config('agent_api.mcp_max_body_bytes', 262_144),
        );
    }
}
