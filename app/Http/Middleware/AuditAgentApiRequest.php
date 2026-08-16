<?php

namespace App\Http\Middleware;

use App\Models\AgentApiAudit;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AuditAgentApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = (string) Str::uuid();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record(
                $request,
                $requestId,
                $this->statusFor($exception),
                $startedAt,
            );

            throw $exception;
        }

        $this->record($request, $requestId, $response->getStatusCode(), $startedAt);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function record(Request $request, string $requestId, int $responseStatus, int $startedAt): void
    {
        $user = $request->user('api');
        $token = $user?->token();
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $tokenId = $attributes['oauth_access_token_id'] ?? null;
        $clientId = $attributes['oauth_client_id'] ?? null;
        $routeName = (string) ($request->route()?->getName() ?? 'agent-api.unknown');
        $actorUserId = $user?->getAuthIdentifier();
        $tokenHash = is_string($tokenId) ? hash('sha256', $tokenId) : null;

        $createdAt = now();
        $samplingKey = $responseStatus === 429
            ? hash('sha256', implode('|', [
                (string) $actorUserId,
                $routeName,
                $createdAt->clone()->utc()->format('YmdHi'),
            ]))
            : null;

        try {
            AgentApiAudit::query()->create([
                'request_id' => $requestId,
                'actor_user_id' => $actorUserId,
                'oauth_client_id' => is_string($clientId) ? $clientId : null,
                'oauth_token_hash' => $tokenHash,
                'event' => 'agent_api.request',
                'route_name' => $routeName,
                'http_method' => $request->method(),
                'response_status' => $responseStatus,
                'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                'sampling_key' => $samplingKey,
                'created_at' => $createdAt,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if ($samplingKey === null || ! AgentApiAudit::query()->where('sampling_key', $samplingKey)->exists()) {
                throw $exception;
            }
        }
    }

    private function statusFor(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
    }
}
