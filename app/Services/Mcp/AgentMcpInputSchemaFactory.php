<?php

namespace App\Services\Mcp;

use App\Models\PhrDocument;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
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

        return $schema;
    }
}
