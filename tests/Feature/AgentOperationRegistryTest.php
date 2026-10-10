<?php

namespace Tests\Feature;

use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiPrincipal;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentOperations;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The agent operations registry is complete: every REST operation declared in
 * AgentRestDocumentation, from which the OpenAPI document is generated, and
 * every MCP tool.
 */
final class AgentOperationRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registry_is_sound_and_covers_the_document_and_every_tool(): void
    {
        $registry = app(AgentOperations::class)->registry();

        $this->assertSame([], $registry->contractViolations());
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

    public function test_tool_scopes_are_those_of_the_rest_operation_they_answer_as(): void
    {
        $operations = app(AgentMcpToolCatalog::class)->operations(app(AgentMcpReadTools::class), app(AgentMcpWriteTools::class));
        $byId = array_column(array_map(static fn (Operation $o): array => [$o->id, $o], $operations), 1, 0);

        $this->assertTrue($byId['capabilities.get']->requirement->public);
        $this->assertSame([AgentApiScopes::CLINICAL_READ], $byId['allergies.list']->requirement->scopes);
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

        $this->assertNotNull($availability->withheld(new AgentApiPrincipal($request), 'oauth.disconnect'));

        $user = $this->createUser();
        $request->setUserResolver(static fn () => $user);
        $this->assertNull($availability->withheld(new AgentApiPrincipal($request), 'oauth.disconnect'));
        $this->assertNotNull($availability->withheld(new AgentApiPrincipal($request), 'patients.list'));
    }
}
