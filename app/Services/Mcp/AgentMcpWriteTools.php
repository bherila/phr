<?php

namespace App\Services\Mcp;

use App\DataTransferObjects\AgentApi\ClinicalRecordUpdateData;
use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DicomUploadFileData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\DataTransferObjects\AgentApi\HealthLogCreateData;
use App\DataTransferObjects\AgentApi\HealthLogEntryAppendData;
use App\DataTransferObjects\AgentApi\ImportReviewData;
use App\DataTransferObjects\AgentApi\PatientCreateData;
use App\DataTransferObjects\AgentApi\RespiratoryEventBatchData;
use App\Services\AgentApi\Client\AgentApiWriteDao;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Closure;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;

/** Thin MCP mutation handlers over the typed REST write DAO. */
final readonly class AgentMcpWriteTools
{
    /**
     * Published to clients, so it must compile in every JSON Schema regex
     * dialect: ChatGPT's tool discovery rejects \p{C}, which Python's re does
     * not support. It refuses only C0/C1 controls; the REST form requests still
     * enforce the full \p{C} rule on external IDs.
     */
    private const string NO_CONTROL_CHARACTERS = '^[^\\x00-\\x1F\\x7F-\\x9F]+$';

    public function __construct(
        private AgentApiWriteDao $api,
        private RequestArguments $requestArguments,
    ) {}

    /** @return array<string, mixed> */
    public function patientsCreate(
        #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] string $display_name,
        #[Schema(maxLength: 50)] ?string $relationship = null,
        #[Schema(format: 'date')] ?string $birth_date = null,
        #[Schema(maxLength: 50)] ?string $sex_at_birth = null,
        #[Schema(maxLength: 10000)] ?string $notes = null,
    ): array {
        return $this->api->patientCreate(PatientCreateData::fromValidated([
            'display_name' => $display_name,
            'relationship' => $relationship,
            'birth_date' => $birth_date,
            'sex_at_birth' => $sex_at_birth,
            'notes' => $notes,
        ]))->toArray();
    }

    public function clinicalUpsertHandler(string $resource): Closure
    {
        return function (
            #[Schema(minimum: 1)] int $patient_id,
            #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] string $external_id,
            #[Schema(minimum: 1)] ?int $source_document_id,
            #[Schema(pattern: '^[a-f0-9]{64}$')] ?string $expected_version,
            #[Schema(type: 'object')] array $data,
        ) use ($resource): array {
            $command = ClinicalUpsertData::fromValidated($resource, [
                'external_id' => $external_id,
                'source_document_id' => $source_document_id,
                'expected_version' => $expected_version,
                'data' => $data,
            ]);

            return $this->api->clinicalUpsert($patient_id, $command)->toArray();
        };
    }

    /** Like the generic upsert, but the external ID is optional: the server derives one for a fill. */
    public function medicationFillUpsertHandler(): Closure
    {
        return function (
            #[Schema(minimum: 1)] int $patient_id,
            #[Schema(minimum: 1)] ?int $source_document_id,
            #[Schema(pattern: '^[a-f0-9]{64}$')] ?string $expected_version,
            #[Schema(type: 'object')] array $data,
            #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] ?string $external_id = null,
        ): array {
            return $this->api->medicationFillUpsert($patient_id, array_filter([
                'external_id' => $external_id,
                'source_document_id' => $source_document_id,
                'expected_version' => $expected_version,
                'data' => $data,
            ], static fn (mixed $value, string $key): bool => $key === 'source_document_id' || $key === 'expected_version' || $value !== null, ARRAY_FILTER_USE_BOTH))->toArray();
        };
    }

    public function clinicalUpdateHandler(string $resource): Closure
    {
        $api = $this->api;
        $requestArguments = $this->requestArguments;

        return function (
            #[Schema(minimum: 1)] int $patient_id,
            #[Schema(minimum: 1)] int $record_id,
            #[Schema(pattern: '^[a-f0-9]{64}$')] string $expected_version,
            RequestContext $context,
            #[Schema(minimum: 1)] ?int $source_document_id = null,
            #[Schema(type: 'object')] ?array $data = null,
        ) use ($api, $requestArguments, $resource): array {
            $validated = [
                'expected_version' => $expected_version,
                'data' => $data,
            ];
            if ($requestArguments->has($context, 'source_document_id')) {
                $validated['source_document_id'] = $source_document_id;
            }

            return $api->clinicalUpdate(
                $patient_id,
                $record_id,
                ClinicalRecordUpdateData::fromValidated($resource, array_filter(
                    $validated,
                    static fn (mixed $value, string $key): bool => $key === 'source_document_id' || $value !== null,
                    ARRAY_FILTER_USE_BOTH,
                )),
            )->toArray();
        };
    }

    /** @return array<string, mixed> */
    public function clinicalRetract(
        string $resource_type,
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $record_id,
        #[Schema(pattern: '^[a-f0-9]{64}$')] string $expected_version,
    ): array {
        return $this->api->clinicalRetract($patient_id, $resource_type, $record_id, $expected_version)->toArray();
    }

    /**
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public function documentsUpload(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] string $external_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] string $filename,
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
    public function dicomUploadsOpen(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(maxLength: 255)] ?string $root_name = null,
    ): array {
        return $this->api->dicomUploadOpen($patient_id, $root_name)->toArray();
    }

    /** @return array<string, mixed> */
    public function dicomUploadsUploadFile(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $upload_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] string $filename,
        #[Schema(minLength: 4, maxLength: DicomUploadFileData::MCP_MAX_BASE64_CHARACTERS, pattern: '^[A-Za-z0-9+/]*={0,2}$')] string $content_base64,
        #[Schema(maxLength: 1024)] ?string $relative_path = null,
    ): array {
        try {
            $file = DicomUploadFileData::fromBase64([
                'filename' => $filename,
                'content_base64' => $content_base64,
                'relative_path' => $relative_path,
            ]);
        } catch (\InvalidArgumentException) {
            throw new ToolCallException('The DICOM content is invalid or too large for MCP.');
        }

        return $this->api->dicomUploadFile($patient_id, $upload_id, $file)->toArray();
    }

    /** @return array<string, mixed> */
    public function dicomUploadsFinalize(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $upload_id,
    ): array {
        return $this->api->dicomUploadFinalize($patient_id, $upload_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function dicomUploadsCancel(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $upload_id,
    ): array {
        return $this->api->dicomUploadCancel($patient_id, $upload_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function reconciliationsApply(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(enum: ['meritain-eob-visits', 'meritain-eob-allergy-procedures', 'delta-dental-eob-visits'])] string $reconciliation,
        #[Schema(pattern: '^[a-f0-9]{64}$')] string $preview_digest,
    ): array {
        return $this->api->reconciliationApply($patient_id, $reconciliation, $preview_digest)->toArray();
    }

    /** @param list<string> $formats
     * @return array<string, mixed>
     */
    public function exportsCreate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minItems: 1, maxItems: 4, uniqueItems: true, items: ['type' => 'string', 'enum' => ['fhir', 'ccda', 'pdf', 'zip']])] array $formats = ['zip'],
    ): array {
        return $this->api->exportCreate($patient_id, $formats)->toArray();
    }

    /** @return array<string, mixed> */
    public function nativeBackupsCreate(#[Schema(minimum: 1)] int $patient_id): array
    {
        return $this->api->nativeBackupCreate($patient_id)->toArray();
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
    public function documentsUploadAccessCreate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(enum: ['eob', 'pharmacy_receipt', 'rx_label', 'statement', 'other'])] string $kind,
        #[Schema(minLength: 1, maxLength: 255)] string $filename,
        #[Schema(enum: ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/tiff', 'text/plain', 'text/html'])] string $content_type,
        #[Schema(minimum: 1, maximum: 52428800)] int $byte_size,
        #[Schema(pattern: '^[a-fA-F0-9]{64}$')] ?string $sha256 = null,
    ): array {
        return $this->api->documentUploadAccessCreate($patient_id, compact('kind', 'filename', 'content_type', 'byte_size', 'sha256'))->toArray();
    }

    /** @return array<string, mixed> */
    public function documentsUploadFinalize(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(pattern: '^[0-9A-Za-z]{26}$')] string $upload_id,
    ): array {
        return $this->api->documentUploadFinalize($patient_id, $upload_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function documentsUploadCancel(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(pattern: '^[0-9A-Za-z]{26}$')] string $upload_id,
    ): array {
        return $this->api->documentUploadCancel($patient_id, $upload_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function medicationFillsResolveDuplicate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $fill_id,
        #[Schema(enum: ['same', 'different'])] string $action,
        #[Schema(pattern: '^[a-f0-9]{64}$')] string $expected_version,
        #[Schema(minimum: 1)] ?int $candidate_fill_id = null,
    ): array {
        return $this->api->medicationFillResolveDuplicate($patient_id, $fill_id, $action, $expected_version, $candidate_fill_id)->toArray();
    }

    /** @return array<string, mixed> */
    public function eobsImport(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $document_id,
    ): array {
        return $this->api->eobImport($patient_id, $document_id)->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function eobsUpsert(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $source_document_id,
        #[Schema(type: 'object')] array $data,
        #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] ?string $external_id = null,
        #[Schema(pattern: '^[a-f0-9]{64}$')] ?string $expected_version = null,
    ): array {
        return $this->api->eobUpsert($patient_id, array_filter([
            'external_id' => $external_id,
            'source_document_id' => $source_document_id,
            'expected_version' => $expected_version,
            'data' => $data,
        ], static fn (mixed $value): bool => $value !== null))->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function eobsUpdate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minimum: 1)] int $eob_id,
        #[Schema(pattern: '^[a-f0-9]{64}$')] string $expected_version,
        #[Schema(type: 'object')] array $data,
        #[Schema(minimum: 1)] ?int $source_document_id = null,
    ): array {
        return $this->api->eobUpdate($patient_id, $eob_id, array_filter([
            'expected_version' => $expected_version,
            'source_document_id' => $source_document_id,
            'data' => $data,
        ], static fn (mixed $value): bool => $value !== null))->toArray();
    }

    /** @return array<string, mixed> */
    public function healthLogsCreate(
        #[Schema(minimum: 1)] int $patient_id,
        #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] string $external_id,
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
        #[Schema(minLength: 1, maxLength: 255, pattern: self::NO_CONTROL_CHARACTERS)] string $external_id,
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
