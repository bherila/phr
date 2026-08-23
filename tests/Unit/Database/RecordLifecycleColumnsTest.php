<?php

namespace Tests\Unit\Database;

use App\Models\PhrDocument;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The lifecycle columns must reach every table a client can be expected to
 * mirror, or that resource silently keeps the divergence this work removes.
 */
final class RecordLifecycleColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_agent_writable_table_records_deletion_and_retraction(): void
    {
        foreach ($this->agentWritableTables() as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'deleted_at'),
                "{$table} cannot record a browser deletion, so a mirror of it diverges permanently.",
            );
            $this->assertTrue(
                Schema::hasColumn($table, 'retracted_at'),
                "{$table} cannot record a source retraction.",
            );
        }
    }

    /** @return list<string> */
    private function agentWritableTables(): array
    {
        $tables = [];
        foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
            $definition = AgentClinicalResourceCatalog::definition($resource);
            $this->assertIsArray($definition);
            /** @var Model $model */
            $model = new $definition['model'];
            $tables[] = $model->getTable();
        }
        // documents.upload is an agent write path outside the clinical catalog.
        $tables[] = (new PhrDocument)->getTable();

        return $tables;
    }
}
