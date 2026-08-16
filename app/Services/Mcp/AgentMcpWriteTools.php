<?php

namespace App\Services\Mcp;

use App\DataTransferObjects\AgentApi\ClinicalUpsertData;
use App\Services\AgentApi\Client\AgentApiWriteDao;
use Closure;
use Mcp\Capability\Attribute\Schema;

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
}
