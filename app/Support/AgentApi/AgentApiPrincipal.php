<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\Capabilities\AuthenticatedPrincipal;
use Illuminate\Http\Request;

/**
 * The caller of the agent API, as the capability registry sees it: the
 * scopes its OAuth credential was granted. Patient access is decided per
 * record by the API itself, so no operation is withheld for it here.
 */
final readonly class AgentApiPrincipal implements AuthenticatedPrincipal
{
    public function __construct(private Request $request) {}

    public function isAuthenticated(): bool
    {
        return $this->request->user('api') !== null;
    }

    public function hasScope(string $scope): bool
    {
        return (bool) $this->request->user('api')?->tokenCan($scope);
    }

    public function can(string $permission): bool
    {
        return true;
    }

    public function allowsGroup(?string $group): bool
    {
        return true;
    }
}
