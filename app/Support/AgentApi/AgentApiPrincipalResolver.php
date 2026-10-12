<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\PrincipalResolver;
use Illuminate\Http\Request;

final class AgentApiPrincipalResolver implements PrincipalResolver
{
    public function principal(Request $request): Principal
    {
        return new AgentApiPrincipal($request);
    }
}
