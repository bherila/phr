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

    public function test_the_column_and_the_cast_that_declares_it_stay_in_agreement(): void
    {
        // Read paths opt into the lifecycle filter by asking the model whether it
        // casts retracted_at, because the shared controllers also serve resources
        // this lifecycle does not cover. A column without its cast would be
        // silently unfiltered, which is the failure worth pinning.
        foreach ($this->agentWritableModels() as $model) {
            $this->assertTrue(
                $model->hasCast('retracted_at'),
                $model::class.' has the column but does not declare it, so reads will not filter it.',
            );
        }
    }

    /** @return list<string> */
    private function agentWritableTables(): array
    {
        return array_map(
            static fn (Model $model): string => $model->getTable(),
            $this->agentWritableModels(),
        );
    }

    /** @return list<Model> */
    private function agentWritableModels(): array
    {
        $models = [];
        foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
            $definition = AgentClinicalResourceCatalog::definition($resource);
            $this->assertIsArray($definition);
            /** @var Model $model */
            $model = new $definition['model'];
            $models[] = $model;
        }
        // documents.upload is an agent write path outside the clinical catalog.
        $models[] = new PhrDocument;

        return $models;
    }
}
