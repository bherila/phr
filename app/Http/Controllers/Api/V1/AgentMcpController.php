<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mcp\AgentMcpServerFactory;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

final class AgentMcpController extends Controller
{
    public function __invoke(Request $request, AgentMcpServerFactory $servers): Response
    {
        $httpFactory = new HttpFactory;
        $psrRequest = (new PsrHttpFactory($httpFactory, $httpFactory, $httpFactory, $httpFactory))
            ->createRequest($request);
        $transport = new StreamableHttpTransport(
            request: $psrRequest,
            responseFactory: $httpFactory,
            streamFactory: $httpFactory,
            middleware: [
                new CorsMiddleware(allowedOrigins: $this->allowedOrigins()),
                new DnsRebindingProtectionMiddleware(allowedHosts: $this->allowedHosts()),
                new ProtocolVersionMiddleware,
            ],
            maxBodyBytes: (int) config('agent_api.mcp_max_body_bytes', 262_144),
        );
        $response = $servers->make($request)->run($transport);
        $streamed = str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');

        return (new HttpFoundationFactory)->createResponse($response, $streamed);
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        $origins = config('agent_api.mcp_allowed_origins', []);

        return is_array($origins)
            ? array_values(array_filter($origins, static fn (mixed $origin): bool => is_string($origin) && $origin !== ''))
            : [];
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $urls = [config('app.url'), ...$this->allowedOrigins()];
        $hosts = [];
        foreach ($urls as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
