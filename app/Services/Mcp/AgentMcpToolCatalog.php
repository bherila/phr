<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentClinicalResourceCatalog;

/** Fixed allow-list of versioned REST operations exposed through MCP. */
final class AgentMcpToolCatalog
{
    /** @return list<AgentMcpToolDefinition> */
    public function definitions(AgentMcpReadTools $reads, AgentMcpWriteTools $writes): array
    {
        $definitions = [
            $this->method('capabilities.get', 'Get capabilities', 'Discover the PHR API version, scopes, operations, resources, and limits.', $reads, 'capabilitiesGet'),
            $this->method('identity.get', 'Get identity', 'Return the authorized account identity and granted OAuth scopes.', $reads, 'identityGet'),
            $this->method('patients.list', 'List patients', 'List only patients accessible to the authorized account, with bounded pagination.', $reads, 'patientsList'),
            $this->method('patients.get', 'Get patient', 'Get one accessible patient and its current access metadata.', $reads, 'patientsGet'),
            $this->method('records.search', 'Search records', 'Search clinical records using the versioned REST filters and cursor pagination.', $reads, 'recordsSearch'),
            $this->method('timeline.list', 'List timeline', 'List a patient timeline using the versioned REST filters and cursor pagination.', $reads, 'timelineList'),
            $this->method('eobs.list', 'List EOBs', 'List explanation-of-benefits records for an accessible patient.', $reads, 'eobsList'),
            $this->method('eobs.get', 'Get EOB', 'Get one explanation-of-benefits record for an accessible patient.', $reads, 'eobsGet'),
            $this->method('eob_lines.list', 'List EOB lines', 'List line items for one accessible explanation-of-benefits record.', $reads, 'eobLinesList'),
            $this->method('eob_lines.get', 'Get EOB line', 'Get one line item from an accessible explanation-of-benefits record.', $reads, 'eobLinesGet'),
            $this->method('evidence.links', 'List evidence links', 'List typed links between a clinical record and its supporting evidence.', $reads, 'evidenceLinks'),
            $this->method('documents.list', 'List documents', 'List document metadata without returning file contents.', $reads, 'documentsList'),
            $this->method('documents.get', 'Get document', 'Get document metadata without returning file contents.', $reads, 'documentsGet'),
            $this->method('documents.download_access.create', 'Create document download access', 'Create short-lived, OAuth-bound download access for one authorized document.', $reads, 'documentsDownloadAccessCreate'),
            new AgentMcpToolDefinition(
                'documents.upload',
                'Upload document',
                'Idempotently upload a small document through the versioned REST API. Use the multipart REST endpoint for larger files.',
                [$writes, 'documentsUpload'],
                readOnly: false,
            ),
            $this->method('imports.list', 'List imports', 'List bounded import-job status for an accessible patient.', $reads, 'importsList'),
            $this->method('imports.get', 'Get import', 'Inspect one import job and its proposed structured records.', $reads, 'importsGet'),
            new AgentMcpToolDefinition(
                'imports.create',
                'Create import',
                'Idempotently enqueue structured extraction for a stored patient document.',
                [$writes, 'importsCreate'],
                readOnly: false,
            ),
            new AgentMcpToolDefinition(
                'imports.review',
                'Review import proposal',
                'Accept or reject one proposed record through the versioned REST workflow.',
                [$writes, 'importsReview'],
                readOnly: false,
                destructive: true,
            ),
            new AgentMcpToolDefinition(
                'imports.retry',
                'Retry import',
                'Safely retry a failed import job that has retry capacity.',
                [$writes, 'importsRetry'],
                readOnly: false,
            ),
        ];

        foreach (AgentClinicalResourceCatalog::ids() as $resource) {
            $toolName = str_replace('-', '_', $resource);
            $title = ucwords(str_replace('-', ' ', $resource));
            $definitions[] = new AgentMcpToolDefinition(
                "{$toolName}.list",
                "List {$title}",
                "List {$title} for an accessible patient through the versioned REST API.",
                $reads->clinicalListHandler($resource),
            );
            $definitions[] = new AgentMcpToolDefinition(
                "{$toolName}.get",
                "Get {$title}",
                "Get one {$title} record for an accessible patient through the versioned REST API.",
                $reads->clinicalGetHandler($resource),
            );
        }

        foreach (AgentClinicalResourceCatalog::writableIds() as $resource) {
            $title = ucwords(str_replace('-', ' ', $resource));
            $definitions[] = new AgentMcpToolDefinition(
                AgentClinicalResourceCatalog::upsertOperationId($resource),
                "Upsert {$title}",
                "Idempotently create or update one {$title} record through the versioned REST API.",
                $writes->clinicalUpsertHandler($resource),
                readOnly: false,
                destructive: true,
            );
        }

        return $definitions;
    }

    private function method(
        string $name,
        string $title,
        string $description,
        AgentMcpReadTools $tools,
        string $method,
    ): AgentMcpToolDefinition {
        return new AgentMcpToolDefinition($name, $title, $description, [$tools, $method]);
    }
}
