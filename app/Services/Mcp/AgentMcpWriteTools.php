<?php

namespace App\Services\Mcp;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\DataTransferObjects\AgentApi\DocumentUploadData;
use App\Services\AgentApi\Client\AgentApiWriteDao;
use Closure;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Thin MCP mutation handlers over the typed REST write DAO. */
final readonly class AgentMcpWriteTools
{
    public function __construct(private AgentApiWriteDao $api) {}

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
}
