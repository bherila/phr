<?php

namespace App\Services\AgentApi\Client;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Request as RequestFacade;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Executes the same versioned REST routes used by external clients without a
 * loopback network dependency. Only the bearer credential and caller address
 * cross the boundary: browser cookies and session state are never forwarded.
 */
final class InternalAgentApiTransport implements AgentApiTransport
{
    public function __construct(
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
        private readonly Request $outerRequest,
        private readonly Application $application,
    ) {}

    public function send(string $method, string $path, array $query = [], ?array $json = null): AgentApiTransportResponse
    {
        $request = Request::create(
            uri: '/api/v1/'.ltrim($path, '/'),
            method: strtoupper($method),
            parameters: $query,
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => (string) $this->outerRequest->header('Authorization', ''),
                'REMOTE_ADDR' => (string) ($this->outerRequest->ip() ?? '127.0.0.1'),
            ],
            content: $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Accept', 'application/json');
        $authorization = $this->outerRequest->header('Authorization');
        if (is_string($authorization) && $authorization !== '') {
            $request->headers->set('Authorization', $authorization);
        }
        if ($json !== null) {
            $request->headers->set('Content-Type', 'application/json');
        }
        $request->setUserResolver(fn (?string $guard = null) => $this->outerRequest->user($guard));

        $previousRequest = $this->application->make('request');
        $this->bindRequest($request);
        try {
            try {
                $response = $this->router->dispatch($request);
            } catch (Throwable $exception) {
                $response = $this->exceptions->render($request, $exception);
            }
        } finally {
            // Controller argument injection and request() must see the REST
            // subrequest while it runs, then the outer MCP request again so its
            // audit middleware and response handling retain the correct route.
            $this->bindRequest($previousRequest);
        }

        return new AgentApiTransportResponse(
            status: $response->getStatusCode(),
            json: $this->decodeJson($response),
        );
    }

    private function bindRequest(Request $request): void
    {
        $this->application->instance('request', $request);
        RequestFacade::clearResolvedInstance();
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(Response $response): ?array
    {
        $content = $response->getContent();
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
