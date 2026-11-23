<?php

namespace App\Services\Mcp;

use App\Http\Requests\AgentApi\ResolveClinicalRecordsRequest;
use App\Models\PhrDocument;
use App\Models\PhrHealthLog;
use App\Models\PhrRespiratoryEvent;
use App\Support\AgentApi\AgentClinicalResourceCatalog;
use App\Support\AgentApi\AgentClinicalWriteSchemaCatalog;
use App\Support\AgentApi\AgentRecordSearchCatalog;
use App\Support\AgentApi\EobWriteRules;
use App\Support\PHR\PhrMedicationCategory;
use App\Support\PHR\PhrReviewStatus;
use App\Support\PHR\Validation\DocumentNoteRules;
use Bherila\McpLaravelBridge\Mcp\ReflectedInputSchemaFactory;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;

/** Builds strict MCP schemas from typed handlers and shared REST catalogs. */
final class AgentMcpInputSchemaFactory
{
    public function __construct(private readonly ReflectedInputSchemaFactory $reflected) {}

    /** @return array<string, mixed> */
    public function for(ToolDefinition $definition): array
    {
        $schema = $this->reflected->for($definition->handler);

        if (in_array($definition->name, ['records.search', 'timeline.list', 'changes.list'], true)) {
            $schema['properties']['resource_type']['items']['enum'] = AgentRecordSearchCatalog::ids();
        }
        if (in_array($definition->name, ['records.search', 'timeline.list'], true)) {
            $schema['properties']['medication_category']['items']['enum'] = PhrMedicationCategory::FILTERS;
        }
        if ($definition->name === 'clinical.list') {
            $schema['properties']['category']['items']['enum'] = PhrMedicationCategory::FILTERS;
        }
        if ($definition->name === 'documents.list') {
            $schema['properties']['document_type']['enum'] = PhrDocument::DOCUMENT_TYPES;
            $schema['properties']['source']['enum'] = PhrDocument::SOURCES;
            $schema['properties']['review_status']['enum'] = PhrReviewStatus::ALL;
        }
        if ($definition->name === 'documents.upload') {
            $schema['properties']['document_type']['enum'] = PhrDocument::DOCUMENT_TYPES;
        }
        if ($definition->name === 'documents.upload_access_create') {
            $schema['properties']['document_type']['enum'] = PhrDocument::DOCUMENT_TYPES;
        }
        if ($definition->name === 'documents.update') {
            $schema['properties']['data'] = [
                'type' => 'object',
                'additionalProperties' => false,
                'minProperties' => 1,
                'properties' => [
                    'title' => ['type' => ['string', 'null'], 'maxLength' => 255],
                    'document_type' => ['type' => 'string', 'enum' => PhrDocument::DOCUMENT_TYPES],
                    'observed_at' => ['type' => ['string', 'null'], 'description' => 'Document date or datetime accepted by the PHR API.'],
                    'summary' => ['type' => ['string', 'null'], 'maxLength' => 20000],
                    'tags' => ['type' => ['array', 'null'], 'maxItems' => 30, 'uniqueItems' => true, 'items' => ['type' => 'string', 'maxLength' => 50]],
                    ...DocumentNoteRules::jsonSchema(),
                ],
            ];
        }
        if ($definition->name === 'documents.create_text') {
            $schema['properties']['document_type']['enum'] = PhrDocument::DOCUMENT_TYPES;
            foreach (DocumentNoteRules::jsonSchema() as $field => $property) {
                $schema['properties'][$field] = $property;
            }
        }
        if (in_array($definition->name, ['eobs.upsert', 'eobs.update'], true)) {
            $schema['properties']['data'] = EobWriteRules::jsonSchema($definition->name === 'eobs.update');
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

        if (in_array($definition->name, ['clinical.list', 'clinical.get'], true)) {
            $schema['properties']['resource_type']['enum'] = AgentClinicalResourceCatalog::ids();
        }
        if (in_array($definition->name, [AgentClinicalResourceCatalog::RESOLVE_OPERATION_ID, AgentClinicalResourceCatalog::RETRACT_OPERATION_ID], true)) {
            $schema['properties']['resource_type']['enum'] = AgentClinicalResourceCatalog::writableIds();
        }

        // Reflection cannot see inside a PHP array parameter, so the batch cap
        // and per-ID constraints have to be declared here or the tool would
        // advertise an unbounded list of anything.
        if ($definition->name === AgentClinicalResourceCatalog::RESOLVE_OPERATION_ID) {
            $schema['properties']['external_ids'] = [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => ResolveClinicalRecordsRequest::MAX_EXTERNAL_IDS,
                'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            ];
        }

        foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
            if ($definition->name === str_replace('-', '_', $resource).'.upsert') {
                $schema['properties']['data'] = AgentClinicalWriteSchemaCatalog::data($resource);
            }
            if ($definition->name === str_replace('-', '_', $resource).'.update') {
                $schema['properties']['data'] = AgentClinicalWriteSchemaCatalog::partialData($resource);
            }
        }

        return $schema;
    }
}
