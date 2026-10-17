<?php

namespace Tests\Feature;

use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiPrincipal;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentOpenApi;
use App\Support\AgentApi\AgentOperations;
use Bherila\McpLaravelBridge\Capabilities\Availability;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\WithheldReason;
use Bherila\McpLaravelBridge\Http\GateOperation;
use Bherila\McpLaravelBridge\Testing\OperationRegistryAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * The agent operations registry is complete: every REST operation declared in
 * AgentRestDocumentation, from which the OpenAPI document is generated, and
 * every MCP tool.
 */
final class AgentOperationRegistryTest extends TestCase
{
    use ConfiguresPassportKeys;
    use OperationRegistryAssertions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_the_registry_is_sound_and_covers_the_document_and_every_tool(): void
    {
        $registry = app(AgentOperations::class)->registry();

        self::assertOperationRegistryContract($registry, AgentOpenApi::checked(), [AgentApiScopes::MCP_USE]);
        $documented = AgentApiResponseSchemaCatalog::operations();
        foreach ($documented as $id => $declared) {
            $operation = $registry->find($id);
            $this->assertNotNull($operation, "{$id} is in the document but not the registry");
            $this->assertSame([$declared['method'], $declared['path']], [$operation->rest?->method, $operation->rest?->path], $id);
        }
        // A tool missing from the registry would be withheld from everyone.
        $tools = app(AgentMcpToolCatalog::class)->definitions(app(AgentMcpReadTools::class), app(AgentMcpWriteTools::class));
        foreach ($tools as $tool) {
            $this->assertNotNull($registry->findByMcpName($tool->name), $tool->name);
        }
    }

    public function test_scope_inventory_changes_only_on_purpose(): void
    {
        self::assertScopeInventory(app(AgentOperations::class)->registry(), __DIR__.'/../Fixtures/agent-api/scope-inventory.json');
    }

    public function test_every_rest_binding_has_its_declared_route_and_registry_gate(): void
    {
        foreach (app(OperationRegistry::class)->all() as $operation) {
            $rest = $operation->rest;
            $this->assertNotNull($rest);
            $route = Route::getRoutes()->getByName($rest->routeName);
            $this->assertNotNull($route, $operation->id);
            $this->assertSame('api/v1'.$rest->path, $route->uri(), $operation->id);
            $this->assertContains($rest->method, $route->methods(), $operation->id);
            $this->assertContains(GateOperation::class.':'.$operation->id, $route->gatherMiddleware(), $operation->id);
        }

        // This includes signed artifact routes that reuse download-access gates.
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || in_array('OPTIONS', $route->methods(), true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertNotEmpty(array_filter($middleware, static fn (string $item): bool => str_starts_with($item, GateOperation::class.':')), $route->uri());
            $this->assertSame([], array_values(array_filter($middleware, static fn (string $item): bool => str_starts_with($item, CheckToken::class))), $route->uri());
        }
    }

    public function test_capabilities_preserve_the_existing_inventory(): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/agent-api/capabilities-operations.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals($expected, $this->getJson('/api/v1/capabilities')->assertOk()->json('operations'));
    }

    public function test_rest_refusals_report_the_registry_reason_and_missing_scope(): void
    {
        Passport::actingAs($this->createUser(), [AgentApiScopes::CLINICAL_WRITE]);
        $this->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('operation', 'identity.get')
            ->assertJsonPath('reason', 'missing_scope')
            ->assertJsonPath('detail', AgentApiScopes::IDENTITY_READ)
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="identity:read"');

        // Having write alone does not satisfy a read-and-write requirement.
        $this->patchJson('/api/v1/patients/1/procedures/1', [])
            ->assertForbidden()
            ->assertJsonPath('operation', AgentClinicalResourceCatalog::UPDATE_OPERATION_ID)
            ->assertJsonPath('reason', 'missing_scope')
            ->assertJsonPath('detail', AgentApiScopes::CLINICAL_READ);
    }

    public function test_preview_is_an_mcp_discovery_dependency_but_not_a_rest_apply_permission(): void
    {
        $actor = $this->createUser();
        Passport::actingAs($actor, [AgentApiScopes::RECONCILIATION_WRITE]);
        $request = Request::create('/api/v1/mcp');
        $request->setUserResolver(static fn () => $actor);
        $principal = new AgentApiPrincipal($request);
        $this->assertSame(WithheldReason::DependsOn, app(AgentOperations::class)->availability()->withheld($principal, 'reconciliations.apply')?->reason);
        $this->assertNull(app(Availability::class)->withheld($principal, 'reconciliations.apply'));
    }

    public function test_tool_scopes_are_those_of_the_rest_operation_they_answer_as(): void
    {
        $operations = app(AgentMcpToolCatalog::class)->operations(app(AgentMcpReadTools::class), app(AgentMcpWriteTools::class));
        $byId = array_column(array_map(static fn (Operation $o): array => [$o->id, $o], $operations), 1, 0);

        $this->assertTrue($byId['capabilities.get']->requirement->public);
        $this->assertSame([AgentApiScopes::CLINICAL_READ], $byId['clinical.list']->requirement->scopes);
        // The generic clinical tools share their REST operation's registry
        // entry, so their requirement is the one that gates the REST route.
        foreach (['clinical.list', 'clinical.get', AgentClinicalResourceCatalog::RESOLVE_OPERATION_ID, AgentClinicalResourceCatalog::RETRACT_OPERATION_ID] as $id) {
            $this->assertSame(AgentApiResponseSchemaCatalog::operations()[$id]['scopes'], $byId[$id]->requirement->scopes, $id);
        }
        $this->assertSame(
            AgentApiResponseSchemaCatalog::operations()[AgentClinicalResourceCatalog::UPDATE_OPERATION_ID]['scopes'],
            $byId['procedures.update']->requirement->scopes,
        );
        $this->assertContains(AgentApiScopes::CLINICAL_WRITE, $byId['procedures.update']->requirement->scopes);
    }

    public function test_self_revocation_needs_any_credential_and_no_scope(): void
    {
        $availability = app(AgentOperations::class)->availability();
        $request = Request::create('/api/v1/me');

        $this->assertSame(WithheldReason::Unauthenticated, $availability->withheld(new AgentApiPrincipal($request), 'oauth.disconnect')?->reason);

        $user = $this->createUser();
        $request->setUserResolver(static fn () => $user);
        $this->assertNull($availability->withheld(new AgentApiPrincipal($request), 'oauth.disconnect'));
        $this->assertNotNull($availability->withheld(new AgentApiPrincipal($request), 'patients.list'));
    }
}
