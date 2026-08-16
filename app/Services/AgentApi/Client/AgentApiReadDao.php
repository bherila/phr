<?php

namespace App\Services\AgentApi\Client;

/**
 * Typed data-access boundary for the reusable v1 REST read surface.
 * MCP tools delegate here; this class intentionally knows routes, not models.
 */
final class AgentApiReadDao
{
    public function __construct(private readonly AgentApiTransport $transport) {}

    public function capabilities(): AgentApiPayload
    {
        return $this->get('capabilities', [], ['api_version', 'scopes', 'operations']);
    }

    public function identity(): AgentApiPayload
    {
        return $this->get('me', [], ['identity', 'scopes']);
    }

    public function patients(
        int $limit = 25,
        ?string $cursor = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        string $archived = 'include',
    ): AgentApiPayload {
        return $this->page('patients', compact('limit', 'cursor') + [
            'updated_after' => $updatedAfter,
            'updated_before' => $updatedBefore,
            'archived' => $archived,
        ]);
    }

    public function patient(int $patientId): AgentApiPayload
    {
        return $this->item("patients/{$patientId}");
    }

    /** @param list<string>|null $resourceTypes */
    public function records(
        int $patientId,
        string $view,
        int $limit = 25,
        ?string $cursor = null,
        ?array $resourceTypes = null,
        ?string $query = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $provider = null,
        ?string $facility = null,
        ?string $code = null,
        ?string $source = null,
        ?string $reviewStatus = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
    ): AgentApiPayload {
        $operation = $view === 'timeline' ? 'timeline' : 'records/search';

        return $this->page("patients/{$patientId}/{$operation}", [
            'limit' => $limit, 'cursor' => $cursor, 'resource_type' => $resourceTypes,
            'q' => $query, 'date_from' => $dateFrom, 'date_to' => $dateTo,
            'provider' => $provider, 'facility' => $facility, 'code' => $code,
            'source' => $source, 'review_status' => $reviewStatus,
            'updated_after' => $updatedAfter, 'updated_before' => $updatedBefore,
        ]);
    }

    public function clinicalRecords(
        int $patientId,
        string $resource,
        int $limit = 25,
        ?string $cursor = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $importSource = null,
        ?int $sourceDocumentId = null,
        ?string $archived = null,
    ): AgentApiPayload {
        return $this->page("patients/{$patientId}/{$resource}", [
            'limit' => $limit, 'cursor' => $cursor,
            'updated_after' => $updatedAfter, 'updated_before' => $updatedBefore,
            'import_source' => $importSource, 'source_document_id' => $sourceDocumentId,
            'archived' => $archived,
        ]);
    }

    public function clinicalRecord(int $patientId, string $resource, int $recordId): AgentApiPayload
    {
        return $this->item("patients/{$patientId}/{$resource}/{$recordId}", ['resource_type', 'patient_id', 'data']);
    }

    public function eobs(
        int $patientId,
        int $limit = 25,
        ?string $cursor = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $importSource = null,
        ?int $sourceDocumentId = null,
        ?string $claimType = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): AgentApiPayload {
        return $this->page("patients/{$patientId}/eobs", [
            'limit' => $limit, 'cursor' => $cursor,
            'updated_after' => $updatedAfter, 'updated_before' => $updatedBefore,
            'import_source' => $importSource, 'source_document_id' => $sourceDocumentId,
            'claim_type' => $claimType, 'date_from' => $dateFrom, 'date_to' => $dateTo,
        ]);
    }

    public function eob(int $patientId, int $eobId): AgentApiPayload
    {
        return $this->item("patients/{$patientId}/eobs/{$eobId}", ['resource_type', 'patient_id', 'data']);
    }

    public function eobLines(
        int $patientId,
        int $eobId,
        int $limit = 25,
        ?string $cursor = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
    ): AgentApiPayload {
        return $this->page("patients/{$patientId}/eobs/{$eobId}/lines", [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_after' => $updatedAfter,
            'updated_before' => $updatedBefore,
        ]);
    }

    public function eobLine(int $patientId, int $eobId, int $lineId): AgentApiPayload
    {
        return $this->item("patients/{$patientId}/eobs/{$eobId}/lines/{$lineId}", ['resource_type', 'patient_id', 'data']);
    }

    public function evidenceLinks(
        int $patientId,
        string $resourceType,
        int $resourceId,
        int $limit = 25,
        ?string $cursor = null,
    ): AgentApiPayload {
        return $this->page("patients/{$patientId}/evidence-links", [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'limit' => $limit,
            'cursor' => $cursor,
        ]);
    }

    public function documents(
        int $patientId,
        int $limit = 25,
        ?string $cursor = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $documentType = null,
        ?string $source = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $tag = null,
    ): AgentApiPayload {
        return $this->page("patients/{$patientId}/documents", [
            'limit' => $limit, 'cursor' => $cursor,
            'updated_after' => $updatedAfter, 'updated_before' => $updatedBefore,
            'document_type' => $documentType, 'source' => $source,
            'date_from' => $dateFrom, 'date_to' => $dateTo, 'tag' => $tag,
        ]);
    }

    public function document(int $patientId, int $documentId): AgentApiPayload
    {
        return $this->item("patients/{$patientId}/documents/{$documentId}", ['resource_type', 'patient_id', 'data']);
    }

    public function documentDownloadAccess(int $patientId, int $documentId): AgentApiPayload
    {
        $response = $this->transport->send('POST', "patients/{$patientId}/documents/{$documentId}/download-access");

        return AgentApiPayload::from($response, ['document_id', 'expires_at', 'download_url']);
    }

    public function imports(
        int $patientId,
        int $limit = 25,
        ?string $cursor = null,
        ?string $status = null,
    ): AgentImportPayload {
        return AgentImportPayload::page($this->transport->send(
            'GET',
            "patients/{$patientId}/imports",
            AgentApiQuery::present(compact('limit', 'cursor', 'status')),
        ));
    }

    public function import(int $patientId, int $importId): AgentImportPayload
    {
        return AgentImportPayload::item($this->transport->send(
            'GET',
            "patients/{$patientId}/imports/{$importId}",
        ));
    }

    public function healthLogEntries(
        int $patientId,
        int $healthLogId,
        int $limit = 25,
        ?string $cursor = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $occurredAfter = null,
        ?string $occurredBefore = null,
    ): AgentApiPayload {
        return $this->page("patients/{$patientId}/health-logs/{$healthLogId}/entries", [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_after' => $updatedAfter,
            'updated_before' => $updatedBefore,
            'occurred_after' => $occurredAfter,
            'occurred_before' => $occurredBefore,
        ]);
    }

    public function healthLogEntry(int $patientId, int $healthLogId, int $entryId): AgentApiPayload
    {
        return $this->item(
            "patients/{$patientId}/health-logs/{$healthLogId}/entries/{$entryId}",
            ['resource_type', 'patient_id', 'health_log_id', 'data'],
        );
    }

    public function respiratoryEvents(
        int $patientId,
        int $limit = 25,
        ?string $cursor = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $occurredAfter = null,
        ?string $occurredBefore = null,
        ?string $eventType = null,
        bool $includeFalsePositives = false,
    ): AgentApiPayload {
        return $this->page("patients/{$patientId}/respiratory-events", [
            'limit' => $limit,
            'cursor' => $cursor,
            'updated_after' => $updatedAfter,
            'updated_before' => $updatedBefore,
            'occurred_after' => $occurredAfter,
            'occurred_before' => $occurredBefore,
            'event_type' => $eventType,
            'include_false_positives' => $includeFalsePositives,
        ]);
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @param  list<string>  $required
     */
    private function get(string $path, array $query, array $required): AgentApiPayload
    {
        return AgentApiPayload::from(
            $this->transport->send('GET', $path, AgentApiQuery::present($query)),
            $required,
        );
    }

    /** @param array<string, scalar|list<scalar>|null> $query */
    private function page(string $path, array $query): AgentApiPayload
    {
        return AgentApiPayload::page(
            $this->transport->send('GET', $path, AgentApiQuery::present($query)),
        );
    }

    /** @param list<string> $required */
    private function item(string $path, array $required = ['data']): AgentApiPayload
    {
        return AgentApiPayload::item($this->transport->send('GET', $path), $required);
    }
}
