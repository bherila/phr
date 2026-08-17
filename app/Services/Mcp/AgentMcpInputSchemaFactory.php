<?php

namespace App\Services\Mcp;

use App\Models\PhrDocument;
use App\Models\PhrHealthLog;
use App\Models\PhrRespiratoryEvent;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentClinicalWriteSchemaCatalog;
use App\Support\AgentApi\AgentRecordSearchCatalog;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\HandlerResolver;
use Mcp\Capability\Discovery\SchemaGenerator;
use Psr\Log\NullLogger;

/** Builds strict MCP schemas from typed handlers and shared REST catalogs. */
final class AgentMcpInputSchemaFactory
{
    /** @return array<string, mixed> */
    public function for(AgentMcpToolDefinition $definition): array
    {
        $generator = new SchemaGenerator(new DocBlockParser(logger: new NullLogger));
        $schema = $generator->generate(HandlerResolver::resolve($definition->handler));
        $schema['additionalProperties'] = false;

        if (in_array($definition->name, ['records.search', 'timeline.list'], true)) {
            $schema['properties']['resource_type']['items']['enum'] = AgentRecordSearchCatalog::ids();
        }
        if ($definition->name === 'documents.list') {
            $schema['properties']['document_type']['enum'] = PhrDocument::DOCUMENT_TYPES;
            $schema['properties']['source']['enum'] = PhrDocument::SOURCES;
        }
        if ($definition->name === 'documents.upload') {
            $schema['properties']['document_type']['enum'] = PhrDocument::DOCUMENT_TYPES;
        }
        if ($definition->name === 'health_logs.create') {
            $schema['properties']['kind']['enum'] = PhrHealthLog::KINDS;
        }
        if ($definition->name === 'respiratory_events.list') {
            $schema['properties']['event_type']['enum'] = PhrRespiratoryEvent::EVENT_TYPES;
        }
        if ($definition->name === 'respiratory_events.ingest') {
            $schema['properties']['events']['items'] = [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['client_event_uuid', 'event_type', 'occurred_at'],
                'properties' => [
                    'client_event_uuid' => ['type' => 'string', 'maxLength' => 64],
                    'event_type' => ['type' => 'string', 'enum' => PhrRespiratoryEvent::EVENT_TYPES],
                    'occurred_at' => ['type' => 'string', 'format' => 'date-time'],
                    'tz_offset_min' => ['type' => ['integer', 'null'], 'minimum' => -720, 'maximum' => 840],
                    'duration_ms' => ['type' => ['integer', 'null'], 'minimum' => 0],
                    'confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                    'burst_count' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'peak_dbfs' => ['type' => ['number', 'null'], 'minimum' => -120, 'maximum' => 20],
                    'mean_dbfs' => ['type' => ['number', 'null'], 'minimum' => -120, 'maximum' => 20],
                    'noise_floor_dbfs' => ['type' => ['number', 'null'], 'minimum' => -120, 'maximum' => 20],
                    'source' => ['type' => ['string', 'null'], 'enum' => [...PhrRespiratoryEvent::SOURCES, null]],
                    'device_id' => ['type' => ['string', 'null'], 'maxLength' => 64],
                    'model_version' => ['type' => ['string', 'null'], 'maxLength' => 64],
                ],
            ];
        }

        $clinicalLists = array_map(
            static fn (string $resource): string => str_replace('-', '_', $resource).'.list',
            AgentClinicalResourceCatalog::ids(),
        );
        if (in_array($definition->name, $clinicalLists, true)) {
            if ($definition->name === 'health_logs.list') {
                unset(
                    $schema['properties']['import_source'],
                    $schema['properties']['source_document_id'],
                );
            } else {
                unset($schema['properties']['archived']);
            }
        }

        foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
            if ($definition->name === str_replace('-', '_', $resource).'.upsert') {
                $schema['properties']['data'] = AgentClinicalWriteSchemaCatalog::data($resource);
            }
        }

        return $schema;
    }
}
