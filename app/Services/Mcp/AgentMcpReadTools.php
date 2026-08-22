<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\Client\AgentApiReadDao;
use Closure;
use Mcp\Capability\Attribute\Schema;

/** Thin MCP handlers over the typed REST DAO. */
final class AgentMcpReadTools
{
    public function __construct(private readonly AgentApiReadDao $api) {}

    /** @return array<string, mixed> */
    public function capabilitiesGet(): array
    {
        return $this->api->capabilities()->toArray();
    }

    /** @return array<string, mixed> */
    public function identityGet(): array
    {
        return $this->api->identity()->toArray();
    }

    /** @return array<string, mixed> */
    public function patientsList(
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(enum: ['include', 'exclude', 'only'])] string $archived = 'include',
    ): array {
        return $this->api->patients($limit, $cursor, $updated_after, $updated_before, $archived)->toArray();
    }

    /** @return array<string, mixed> */
    public function patientsGet(#[Schema(minimum: 1)] int $patient_id): array
    {
        return $this->api->patient($patient_id)->toArray();
    }

    /**
     * @param  list<string>|null  $resource_type
     * @return array<string, mixed>
     */
    public function recordsSearch(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(minItems: 1, maxItems: 9, uniqueItems: true, items: ['type' => 'string'])] ?array $resource_type = null,
        #[Schema(maxLength: 200)] ?string $q = null,
        #[Schema(format: 'date')] ?string $date_from = null,
        #[Schema(format: 'date')] ?string $date_to = null,
        #[Schema(maxLength: 200)] ?string $provider = null,
        #[Schema(maxLength: 200)] ?string $facility = null,
        #[Schema(maxLength: 100)] ?string $code = null,
        #[Schema(maxLength: 100)] ?string $source = null,
        #[Schema(maxLength: 50)] ?string $review_status = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
    ): array {
        return $this->records(
            'search', $patient_id, $limit, $cursor, $resource_type, $q,
            $date_from, $date_to, $provider, $facility, $code, $source,
            $review_status, $updated_after, $updated_before,
        );
    }

    /**
     * @param  list<string>|null  $resource_type
     * @return array<string, mixed>
     */
    public function timelineList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(minItems: 1, maxItems: 9, uniqueItems: true, items: ['type' => 'string'])] ?array $resource_type = null,
        #[Schema(maxLength: 200)] ?string $q = null,
        #[Schema(format: 'date')] ?string $date_from = null,
        #[Schema(format: 'date')] ?string $date_to = null,
        #[Schema(maxLength: 200)] ?string $provider = null,
        #[Schema(maxLength: 200)] ?string $facility = null,
        #[Schema(maxLength: 100)] ?string $code = null,
        #[Schema(maxLength: 100)] ?string $source = null,
        #[Schema(maxLength: 50)] ?string $review_status = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
    ): array {
        return $this->records(
            'timeline', $patient_id, $limit, $cursor, $resource_type, $q,
            $date_from, $date_to, $provider, $facility, $code, $source,
            $review_status, $updated_after, $updated_before,
        );
    }

    public function clinicalListHandler(string $resource): Closure
    {
        return function (
            #[Schema(minimum: 1)] int $patient_id,
            #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
            #[Schema(maxLength: 2048)] ?string $cursor = null,
            #[Schema(format: 'date-time')] ?string $updated_after = null,
            #[Schema(format: 'date-time')] ?string $updated_before = null,
            #[Schema(maxLength: 100)] ?string $import_source = null,
            #[Schema(minimum: 1)] ?int $source_document_id = null,
            #[Schema(enum: ['include', 'exclude', 'only'])] ?string $archived = null,
        ) use ($resource): array {
            return $this->api->clinicalRecords(
                $patient_id, $resource, $limit, $cursor, $updated_after,
                $updated_before, $import_source, $source_document_id, $archived,
            )->toArray();
        };
    }

    public function clinicalGetHandler(string $resource): Closure
    {
        return fn (
            #[Schema(minimum: 1)] int $patient_id,
            #[Schema(minimum: 1)] int $record_id,
        ): array => $this->api->clinicalRecord($patient_id, $resource, $record_id)->toArray();
    }

    public function clinicalResolveHandler(string $resource): Closure
    {
        return fn (
            #[Schema(minimum: 1)] int $patient_id,
            #[Schema(type: 'array')] array $external_ids,
        ): array => $this->api->resolveClinicalRecords($patient_id, $resource, array_values(array_map(
            static fn (mixed $externalId): string => (string) $externalId,
            $external_ids,
        )))->toArray();
    }

    /** @return array<string, mixed> */
    public function eobsList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(maxLength: 50)] ?string $import_source = null,
        #[Schema(minimum: 1)] ?int $source_document_id = null,
        #[Schema(maxLength: 30)] ?string $claim_type = null,
        #[Schema(format: 'date')] ?string $date_from = null,
        #[Schema(format: 'date')] ?string $date_to = null,
    ): array {
        return $this->api->eobs(
            $patient_id, $limit, $cursor, $updated_after, $updated_before,
            $import_source, $source_document_id, $claim_type, $date_from, $date_to,
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function eobsGet(#[Schema(minimum: 1)] int $patient_id, #[Schema(minimum: 1)] int $eob_id): array
    {
        return $this->api->eob($patient_id, $eob_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function eobLinesList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $eob_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
    ): array {
        return $this->api->eobLines(
            $patient_id,
            $eob_id,
            $limit,
            $cursor,
            $updated_after,
            $updated_before,
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function eobLinesGet(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $eob_id,
        #[Schema(minimum: 1)] int $line_id,
    ): array {
        return $this->api->eobLine($patient_id, $eob_id, $line_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function evidenceLinks(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(enum: ['document', 'eob', 'office-visit', 'procedure'])] string $resource_type,
        #[Schema(minimum: 1)] int $resource_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        return $this->api->evidenceLinks($patient_id, $resource_type, $resource_id, $limit, $cursor)->toArray();
    }

    /** @return array<string, mixed> */
    public function documentsList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(maxLength: 50)] ?string $document_type = null,
        #[Schema(maxLength: 50)] ?string $source = null,
        #[Schema(format: 'date')] ?string $date_from = null,
        #[Schema(format: 'date')] ?string $date_to = null,
        #[Schema(maxLength: 100)] ?string $tag = null,
    ): array {
        return $this->api->documents(
            $patient_id, $limit, $cursor, $updated_after, $updated_before,
            $document_type, $source, $date_from, $date_to, $tag,
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function documentsGet(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $document_id,
    ): array {
        return $this->api->document($patient_id, $document_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function documentsDownloadAccessCreate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $document_id,
    ): array {
        return $this->api->documentDownloadAccess($patient_id, $document_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function importsList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(enum: ['pending', 'processing', 'parsed', 'imported', 'failed', 'queued_tomorrow'])] ?string $status = null,
    ): array {
        return $this->api->imports($patient_id, $limit, $cursor, $status)->toArray();
    }

    /** @return array<string, mixed> */
    public function importsGet(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $import_id,
    ): array {
        return $this->api->import($patient_id, $import_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function healthLogEntriesList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $health_log_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(format: 'date-time')] ?string $occurred_after = null,
        #[Schema(format: 'date-time')] ?string $occurred_before = null,
    ): array {
        return $this->api->healthLogEntries(
            $patient_id,
            $health_log_id,
            $limit,
            $cursor,
            $updated_after,
            $updated_before,
            $occurred_after,
            $occurred_before,
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function healthLogEntriesGet(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $health_log_id,
        #[Schema(minimum: 1)] int $entry_id,
    ): array {
        return $this->api->healthLogEntry($patient_id, $health_log_id, $entry_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function respiratoryEventsList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(format: 'date-time')] ?string $occurred_after = null,
        #[Schema(format: 'date-time')] ?string $occurred_before = null,
        ?string $event_type = null,
        bool $include_false_positives = false,
    ): array {
        return $this->api->respiratoryEvents(
            $patient_id,
            $limit,
            $cursor,
            $updated_after,
            $updated_before,
            $occurred_after,
            $occurred_before,
            $event_type,
            $include_false_positives,
        )->toArray();
    }

    /**
     * @param  list<string>|null  $resourceTypes
     * @return array<string, mixed>
     */
    private function records(
        string $view,
        int $patientId,
        int $limit,
        ?string $cursor,
        ?array $resourceTypes,
        ?string $query,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $provider,
        ?string $facility,
        ?string $code,
        ?string $source,
        ?string $reviewStatus,
        ?string $updatedAfter,
        ?string $updatedBefore,
    ): array {
        return $this->api->records(
            $patientId, $view, $limit, $cursor, $resourceTypes, $query,
            $dateFrom, $dateTo, $provider, $facility, $code, $source,
            $reviewStatus, $updatedAfter, $updatedBefore,
        )->toArray();
    }
}
