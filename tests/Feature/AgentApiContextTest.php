<?php

namespace Tests\Feature;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\ConfiguresPassportKeys;
use Tests\TestCase;

/**
 * The identity endpoint tells a client what its credential can do now and
 * why each other operation is withheld, from the same evaluation that
 * decides its MCP tools.
 */
final class AgentApiContextTest extends TestCase
{
    use ConfiguresPassportKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configurePassportKeys();
    }

    public function test_withheld_operations_carry_their_reason(): void
    {
        Passport::actingAs($this->createUser(), [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PATIENTS_READ, AgentApiScopes::RECONCILIATION_WRITE]);

        $operations = $this->getJson('/api/v1/me')->assertOk()->json('operations');

        $this->assertContains('identity.get', $operations['available']);
        $this->assertContains('patients.list', $operations['available']);
        $this->assertContains('capabilities.get', $operations['available']);
        $this->assertNotContains('allergies.list', $operations['available']);
        $withheld = array_column($operations['withheld'], null, 'operation');
        $this->assertSame(['operation' => 'allergies.list', 'reason' => 'missing_scope', 'detail' => AgentApiScopes::CLINICAL_READ], $withheld['allergies.list']);
        $this->assertSame('missing_scope', $withheld['mcp.exchange']['reason']);
        // Applying a reconciliation needs its preview, which needs a scope this credential lacks.
        $this->assertSame('missing_scope', $withheld['reconciliations.preview']['reason']);
        $this->assertSame(['operation' => 'reconciliations.apply', 'reason' => 'depends_on', 'detail' => 'reconciliations.preview'], $withheld['reconciliations.apply'] ?? null);
        $this->assertSame([], array_intersect($operations['available'], array_keys($withheld)));
    }

    public function test_the_mcp_tools_shown_are_exactly_the_available_operations_with_a_tool(): void
    {
        Passport::actingAs($this->createUser(), [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::CLINICAL_READ]);

        $available = $this->getJson('/api/v1/me')->assertOk()->json('operations.available');
        $session = (string) $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic', 'version' => '1']],
        ], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk()->headers->get('Mcp-Session-Id');
        $tools = array_column($this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass], [
            'Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session,
        ])->assertOk()->json('result.tools'), 'name');

        $this->assertNotSame([], $tools);
        $this->assertSame([], array_values(array_diff($tools, $available)));
    }
}
