<?php

namespace App\Services\Mcp;

use App\Support\AgentApi\AgentClinicalResourceCatalog;

/** Fixed allow-list of the read-only REST operations exposed through MCP. */
final class AgentMcpToolCatalog
{
    /** @return list<AgentMcpToolDefinition> */
    public function definitions(AgentMcpReadTools $tools): array
    {
        $definitions = [
            $this->method('capabilities.get', 'Get capabilities', 'Discover the PHR API version, scopes, operations, resources, and limits.', $tools, 'capabilitiesGet'),
            $this->method('identity.get', 'Get identity', 'Return the authorized account identity and granted OAuth scopes.', $tools, 'identityGet'),
            $this->method('patients.list', 'List patients', 'List only patients accessible to the authorized account, with bounded pagination.', $tools, 'patientsList'),
            $this->method('patients.get', 'Get patient', 'Get one accessible patient and its current access metadata.', $tools, 'patientsGet'),
            $this->method('records.search', 'Search records', 'Search clinical records using the versioned REST filters and cursor pagination.', $tools, 'recordsSearch'),
            $this->method('timeline.list', 'List timeline', 'List a patient timeline using the versioned REST filters and cursor pagination.', $tools, 'timelineList'),
            $this->method('eobs.list', 'List EOBs', 'List explanation-of-benefits records for an accessible patient.', $tools, 'eobsList'),
            $this->method('eobs.get', 'Get EOB', 'Get one explanation-of-benefits record for an accessible patient.', $tools, 'eobsGet'),
            $this->method('eob_lines.list', 'List EOB lines', 'List line items for one accessible explanation-of-benefits record.', $tools, 'eobLinesList'),
            $this->method('eob_lines.get', 'Get EOB line', 'Get one line item from an accessible explanation-of-benefits record.', $tools, 'eobLinesGet'),
            $this->method('evidence.links', 'List evidence links', 'List typed links between a clinical record and its supporting evidence.', $tools, 'evidenceLinks'),
            $this->method('documents.list', 'List documents', 'List document metadata without returning file contents.', $tools, 'documentsList'),
            $this->method('documents.get', 'Get document', 'Get document metadata without returning file contents.', $tools, 'documentsGet'),
            $this->method('documents.download_access.create', 'Create document download access', 'Create short-lived, OAuth-bound download access for one authorized document.', $tools, 'documentsDownloadAccessCreate'),
        ];

        foreach (AgentClinicalResourceCatalog::ids() as $resource) {
            $toolName = str_replace('-', '_', $resource);
            $title = ucwords(str_replace('-', ' ', $resource));
            $definitions[] = new AgentMcpToolDefinition(
                "{$toolName}.list",
                "List {$title}",
                "List {$title} for an accessible patient through the versioned REST API.",
                $tools->clinicalListHandler($resource),
            );
            $definitions[] = new AgentMcpToolDefinition(
                "{$toolName}.get",
                "Get {$title}",
                "Get one {$title} record for an accessible patient through the versioned REST API.",
                $tools->clinicalGetHandler($resource),
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
