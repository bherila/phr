<?php

namespace App\Services\AgentApi\Client;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?AgentApiMultipart $multipart = null,
    ): AgentApiTransportResponse {
        if ($json !== null && $multipart !== null) {
            throw new \LogicException('An agent API request cannot be JSON and multipart.');
        }
        $temporaryPaths = [];
        $files = [];
        $parameters = $query;
        $previousRequest = $this->application->make('request');
        $requestWasBound = false;
        try {
            if ($multipart !== null) {
                $parameters = $multipart->fields;
                foreach ($multipart->files as $name => $file) {
                    $temporaryPath = tempnam(sys_get_temp_dir(), 'phr-agent-');
                    if (! is_string($temporaryPath) || file_put_contents($temporaryPath, $file->contents, LOCK_EX) === false) {
                        if (is_string($temporaryPath)) {
                            $temporaryPaths[] = $temporaryPath;
                        }
                        throw new \RuntimeException('The MCP upload could not be prepared.');
                    }
                    $temporaryPaths[] = $temporaryPath;
                    $files[$name] = new UploadedFile($temporaryPath, $file->filename, test: true);
                }
            }

            $request = Request::create(
                uri: '/api/v1/'.ltrim($path, '/'),
                method: strtoupper($method),
                parameters: $parameters,
                files: $files,
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

            $this->bindRequest($request);
            $requestWasBound = true;
            try {
                $response = $this->router->dispatch($request);
            } catch (Throwable $exception) {
                $response = $this->exceptions->render($request, $exception);
            }

            return new AgentApiTransportResponse(
                status: $response->getStatusCode(),
                json: $this->decodeJson($response),
            );
        } finally {
            // Controller argument injection and request() must see the REST
            // subrequest while it runs, then the outer MCP request again so its
            // audit middleware and response handling retain the correct route.
            if ($requestWasBound) {
                $this->bindRequest($previousRequest);
            }
            $this->removeTemporaryFiles($temporaryPaths);
        }
    }

    /** @param list<string> $paths */
    private function removeTemporaryFiles(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
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
