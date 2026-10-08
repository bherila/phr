<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\Capabilities\Principal;
use Illuminate\Http\Request;

/**
 * The caller of the agent API, as the capability registry sees it: the
 * scopes its OAuth credential was granted. Patient access is decided per
 * record by the API itself, so no operation is withheld for it here.
 */
final readonly class AgentApiPrincipal implements Principal
{
    /** Held by any authenticated credential: for operations that need one but no particular scope. */
    public const string AUTHENTICATED = 'authenticated';

    public function __construct(private Request $request) {}

    public function hasScope(string $scope): bool
    {
        return (bool) $this->request->user('api')?->tokenCan($scope);
    }

    public function can(string $permission): bool
    {
        return $permission !== self::AUTHENTICATED || $this->request->user('api') !== null;
    }

    public function allowsGroup(?string $group): bool
    {
        return true;
    }
}
