<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Http\OperationRoutes;
use Bherila\McpLaravelBridge\Http\PayloadCodecs;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use WeakMap;

/**
 * `Route::operation(...)`, as `OperationRoutes::macro()` registers it, with one
 * REST registry per application instead of one per route.
 *
 * The container binding stays transient for MCP handlers, which need a
 * transport carrying the current request. Routing only reads each operation's
 * REST binding. Resolving the registry once per route rebuilt the whole tool
 * catalog for every one of the 100+ operations, on every uncached boot.
 */
final class AgentOperationRoutes
{
    /** @var WeakMap<Application, OperationRegistry>|null */
    private static ?WeakMap $registries = null;

    public static function macro(): void
    {
        if (Route::hasMacro('operation')) {
            return;
        }
        Route::macro('operation', fn (string $operationId, array|string|Closure $action): RoutingRoute => OperationRoutes::register(
            AgentOperationRoutes::registry(app()),
            $operationId,
            $action,
            app()->bound(PayloadCodecs::class) ? app(PayloadCodecs::class) : null,
        ));
    }

    /** The registry routing reads; the macro calls it by name, as Laravel binds a macro to the router. */
    public static function registry(Application $app): OperationRegistry
    {
        self::$registries ??= new WeakMap;

        return self::$registries[$app] ??= $app->make(OperationRegistry::class);
    }
}
