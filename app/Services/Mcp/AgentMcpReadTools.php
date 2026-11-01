<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\Client\AgentApiReadDao;
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
        #[Schema(minLength: 1, maxLength: 255, description: 'A name printed on a source document. Returns the one accessible patient it matches (case, spacing and LAST, FIRST order ignored), or fails telling you to ask the user. Never guess, and never create a patient.')] ?string $name = null,
        #[Schema(format: 'date', description: 'The date of birth printed on the source; confirms or narrows a name match. Only with name.')] ?string $birth_date = null,
    ): array {
        return $this->api->patients($limit, $cursor, $updated_after, $updated_before, $archived, $name, $birth_date)->toArray();
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
        #[Schema(minItems: 1, maxItems: 10, uniqueItems: true, items: ['type' => 'string'])] ?array $resource_type = null,
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
        #[Schema(minItems: 1, maxItems: 10, uniqueItems: true, items: ['type' => 'string'])] ?array $resource_type = null,
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

    /**
     * @param  list<string>|null  $resource_type
     * @return array<string, mixed>
     */
    public function changesList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(minItems: 1, maxItems: 10, uniqueItems: true, items: ['type' => 'string'])] ?array $resource_type = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time', description: 'Reuse the watermark from the first page until this sync pass completes.')] ?string $watermark = null,
    ): array {
        return $this->api->changes($patient_id, $limit, $cursor, $resource_type, $updated_after, $watermark)->toArray();
    }

    /**
     * One tool for every clinical type: the per-type tools differed only in
     * the route segment, and repeating them multiplied tools/list by nine.
     *
     * @return array<string, mixed>
     */
    public function clinicalList(
        string $resource_type,
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(maxLength: 100, description: 'Not accepted for health-logs.')] ?string $import_source = null,
        #[Schema(minimum: 1, description: 'Not accepted for health-logs.')] ?int $source_document_id = null,
        #[Schema(enum: ['include', 'exclude', 'only'], description: 'Accepted only for health-logs.')] ?string $archived = null,
    ): array {
        return $this->api->clinicalRecords(
            $patient_id, $resource_type, $limit, $cursor, $updated_after,
            $updated_before, $import_source, $source_document_id, $archived,
        )->toArray();
    }

    /**
     * Fills have their own listing because they filter by date, medication,
     * pharmacy and status; it is the clinical list with those filters.
     *
     * @return array<string, mixed>
     */
    public function medicationFillsList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date', description: 'Earliest fill date, inclusive.')] ?string $filled_from = null,
        #[Schema(format: 'date', description: 'Latest fill date, inclusive.')] ?string $filled_to = null,
        #[Schema(minimum: 1)] ?int $medication_id = null,
        #[Schema(maxLength: 255, description: 'Case-insensitive pharmacy name substring.')] ?string $pharmacy = null,
        #[Schema(enum: ['pending_review', 'confirmed', 'rejected'])] ?string $review_status = null,
        #[Schema(enum: ['resolved', 'ambiguous', 'unresolved', 'manual'])] ?string $ndc_status = null,
    ): array {
        return $this->api->clinicalRecords(
            $patient_id, 'medication-fills', $limit, $cursor,
            filters: compact('filled_from', 'filled_to', 'medication_id', 'pharmacy', 'review_status', 'ndc_status'),
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function medicationFillsGet(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $fill_id,
    ): array {
        return $this->api->clinicalRecord($patient_id, 'medication-fills', $fill_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function ndcLookup(
        #[Schema(minLength: 1, maxLength: 64, description: 'A 10- or 11-digit NDC, with or without hyphens, exactly as read from the source.')] string $ndc,
    ): array {
        return $this->api->ndcLookup($ndc)->toArray();
    }

    /** @return array<string, mixed> */
    public function ndcSearch(
        #[Schema(minLength: 1, maxLength: 255, description: 'Brand or generic drug name.')] string $name,
        #[Schema(maxLength: 100)] ?string $strength = null,
        #[Schema(maxLength: 100)] ?string $dosage_form = null,
        #[Schema(maxLength: 100)] ?string $route = null,
        #[Schema(maxLength: 255)] ?string $labeler = null,
        #[Schema(maxLength: 20, description: 'The unit count on the package, such as 90.')] ?string $package_size = null,
    ): array {
        return $this->api->ndcSearch(compact('name', 'strength', 'dosage_form', 'route', 'labeler', 'package_size'))->toArray();
    }

    /** @return array<string, mixed> */
    public function ndcLabel(
        #[Schema(maxLength: 64, description: 'The 10-digit package NDC (ndc_10) from ndc_lookup. Give this or spl_set_id.')] ?string $ndc_10 = null,
        #[Schema(maxLength: 64, description: 'The SPL set id of the label. Give this or ndc_10.')] ?string $spl_set_id = null,
    ): array {
        return $this->api->ndcLabel($ndc_10, $spl_set_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function clinicalGet(
        string $resource_type,
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $record_id,
    ): array {
        return $this->api->clinicalRecord($patient_id, $resource_type, $record_id)->toArray();
    }

    /**
     * @param  array<array-key, mixed>  $external_ids
     * @return array<string, mixed>
     */
    public function clinicalResolve(
        string $resource_type,
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(type: 'array')] array $external_ids,
    ): array {
        return $this->api->resolveClinicalRecords($patient_id, $resource_type, array_values(array_map(
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
        #[Schema(enum: ['document', 'eob', 'eob-line', 'medication-fill', 'office-visit', 'procedure'])] string $resource_type,
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
    public function dicomStudiesList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(maxLength: 16)] ?string $modality = null,
        #[Schema(format: 'date')] ?string $date_from = null,
        #[Schema(format: 'date')] ?string $date_to = null,
    ): array {
        return $this->api->dicomStudies(
            $patient_id, $limit, $cursor, $updated_after, $updated_before, $modality, $date_from, $date_to,
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function dicomStudiesGet(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $study_id,
    ): array {
        return $this->api->dicomStudy($patient_id, $study_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function dicomSeriesList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $study_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
        #[Schema(maxLength: 16)] ?string $modality = null,
    ): array {
        return $this->api->dicomSeries(
            $patient_id, $study_id, $limit, $cursor, $updated_after, $updated_before, $modality,
        )->toArray();
    }

    /** @return array<string, mixed> */
    public function reconciliationsPreview(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(enum: ['meritain-eob-visits', 'meritain-eob-allergy-procedures', 'delta-dental-eob-visits'])] string $reconciliation,
    ): array {
        return $this->api->reconciliationPreview($patient_id, $reconciliation)->toArray();
    }

    /** @return array<string, mixed> */
    public function exportsList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
    ): array {
        return $this->api->exports($patient_id, $limit, $cursor, $updated_after, $updated_before)->toArray();
    }

    /** @return array<string, mixed> */
    public function nativeBackupsList(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
        #[Schema(format: 'date-time')] ?string $updated_after = null,
        #[Schema(format: 'date-time')] ?string $updated_before = null,
    ): array {
        return $this->api->nativeBackups($patient_id, $limit, $cursor, $updated_after, $updated_before)->toArray();
    }

    /** @return array<string, mixed> */
    public function exportsDownloadAccessCreate(#[Schema(minimum: 1)] int $patient_id, #[Schema(minimum: 1)] int $export_id): array
    {
        return $this->api->exportDownloadAccess($patient_id, $export_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function nativeBackupsDownloadAccessCreate(#[Schema(minimum: 1)] int $patient_id, #[Schema(minimum: 1)] int $backup_id): array
    {
        return $this->api->nativeBackupDownloadAccess($patient_id, $backup_id)->toArray();
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
