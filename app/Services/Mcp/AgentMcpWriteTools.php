<?php

namespace App\Services\Mcp;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\DataTransferObjects\AgentApi\HealthLogEntryAppendData;
use App\DataTransferObjects\AgentApi\ImportReviewData;
use App\DataTransferObjects\AgentApi\RespiratoryEventBatchData;
use App\Services\AgentApi\Client\AgentApiWriteDao;
use Closure;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/** Thin MCP mutation handlers over the typed REST write DAO. */
final readonly class AgentMcpWriteTools
{
    public function __construct(
        private AgentApiWriteDao $api,
        private AgentMcpRequestArguments $requestArguments,
    ) {}

    public function clinicalUpsertHandler(string $resource): Closure
    {
        return function (
            #[Schema(minimum: 1)] int $patient_id,
            #[Schema(minLength: 1, maxLength: 255, pattern: '^[^\\p{C}]+$')] string $external_id,
            #[Schema(minimum: 1)] ?int $source_document_id,
            #[Schema(enum: ['pending_review', 'confirmed'])] string $review_status,
            #[Schema(pattern: '^[a-f0-9]{64}$')] ?string $expected_version,
            #[Schema(type: 'object')] array $data,
        ) use ($resource): array {
            $command = ClinicalUpsertData::fromValidated($resource, [
                'external_id' => $external_id,
                'source_document_id' => $source_document_id,
                'review_status' => $review_status,
                'expected_version' => $expected_version,
                'data' => $data,
            ]);

            return $this->api->clinicalUpsert($patient_id, $command)->toArray();
        };
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public function documentsUpload(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: '^[^\\p{C}]+$')] string $external_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: '^[^\\p{C}]+$')] string $filename,
        #[Schema(minLength: 4, maxLength: DocumentUploadData::MCP_MAX_BASE64_CHARACTERS, pattern: '^[A-Za-z0-9+/]*={0,2}$')] string $content_base64,
        string $document_type,
        #[Schema(maxLength: 255)] ?string $title = null,
        #[Schema(description: 'Document date or datetime accepted by the PHR API.')] ?string $observed_at = null,
        #[Schema(maxLength: 20000)] ?string $summary = null,
        #[Schema(items: ['type' => 'string', 'maxLength' => 50], maxItems: 30, uniqueItems: true)] array $tags = [],
    ): array {
        try {
            $command = DocumentUploadData::fromBase64([
                'external_id' => $external_id,
                'filename' => $filename,
                'content_base64' => $content_base64,
                'title' => $title,
                'document_type' => $document_type,
                'observed_at' => $observed_at,
                'summary' => $summary,
                'tags' => $tags,
            ]);
        } catch (\InvalidArgumentException) {
            throw new ToolCallException('The document content is invalid or too large for MCP.');
        }

        return $this->api->documentUpload($patient_id, $command)->toArray();
    }

    /** @return array<string, mixed> */
    public function importsCreate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $document_id,
    ): array {
        return $this->api->importCreate($patient_id, $document_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function importsRetry(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $import_id,
    ): array {
        return $this->api->importRetry($patient_id, $import_id)->toArray();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public function importsReview(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $import_id,
        #[Schema(minimum: 1)] int $result_id,
        #[Schema(enum: ['accept', 'reject'])] string $action,
        #[Schema(type: 'object')] ?array $payload = null,
    ): array {
        try {
            $review = ImportReviewData::make($action, $payload);
        } catch (\InvalidArgumentException) {
            throw new ToolCallException('The import review request is invalid.');
        }

        return $this->api->importReview($patient_id, $import_id, $result_id, $review)->toArray();
    }

    /** @return array<string, mixed> */
    public function healthLogsCreate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: '^[^\\p{C}]+$')] string $external_id,
        #[Schema(minLength: 1, maxLength: 120)] string $name,
        string $kind,
        #[Schema(maxLength: 1000)] ?string $description = null,
        #[Schema(format: 'date-time')] ?string $archived_at = null,
    ): array {
        return $this->api->healthLogCreate($patient_id, HealthLogCreateData::fromValidated([
            'external_id' => $external_id,
            'name' => $name,
            'kind' => $kind,
            'description' => $description,
            'archived_at' => $archived_at,
        ]))->toArray();
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>
     */
    public function healthLogEntriesAppend(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $health_log_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: '^[^\\p{C}]+$')] string $external_id,
        #[Schema(format: 'date-time')] string $occurred_at,
        RequestContext $context,
        #[Schema(maxLength: 255)] ?string $title = null,
        #[Schema(maxLength: 10000)] ?string $notes = null,
        #[Schema(minimum: 0, maximum: 10)] ?int $intensity = null,
        #[Schema(items: ['type' => 'string', 'maxLength' => 50], maxItems: 20, uniqueItems: true)] array $tags = [],
        #[Schema(type: 'object')] ?array $details = null,
    ): array {
        return $this->api->healthLogEntryAppend(
            $patient_id,
            $health_log_id,
            HealthLogEntryAppendData::fromValidated([
                'external_id' => $external_id,
                'occurred_at' => $occurred_at,
                'title' => $title,
                'notes' => $notes,
                'intensity' => $intensity,
                'tags' => $tags,
                'details' => $this->requestArguments->value($context, 'details', $details),
            ]),
        )->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function respiratoryEventsIngest(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(type: 'array', minItems: 1, maxItems: 500, items: ['type' => 'object'])] array $events,
    ): array {
        try {
            $batch = RespiratoryEventBatchData::from($events);
        } catch (\InvalidArgumentException) {
            throw new ToolCallException('The respiratory event batch is invalid.');
        }

        return $this->api->respiratoryEventsIngest($patient_id, $batch)->toArray();
    }
}
