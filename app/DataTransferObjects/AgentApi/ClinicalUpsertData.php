<?php

namespace App\DataTransferObjects\AgentApi;

use App\Support\AgentApi\AgentClinicalResourceCatalog;
use InvalidArgumentException;

/**
 * Validated, immutable command shared by REST controllers and REST client DAOs.
 *
 * `review_status` is deliberately absent: the server owns the review lifecycle,
 * so an agent has nothing to say about it on the way in.
 */
final readonly class ClinicalUpsertData
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function __construct(
        public string $resource,
        public string $externalId,
        public ?int $sourceDocumentId,
        public ?string $expectedVersion,
        public array $attributes,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(string $resource, array $validated): self
    {
        if (! in_array($resource, AgentClinicalResourceCatalog::writableIds(), true)) {
            throw new InvalidArgumentException('Unsupported writable clinical resource.');
        }

        return new self(
            resource: $resource,
            externalId: (string) $validated['external_id'],
            sourceDocumentId: isset($validated['source_document_id']) ? (int) $validated['source_document_id'] : null,
            expectedVersion: isset($validated['expected_version']) ? (string) $validated['expected_version'] : null,
            attributes: (array) $validated['data'],
        );
    }

    /** @return array<string, mixed> */
    public function toRequestPayload(): array
    {
        return [
            'external_id' => $this->externalId,
            'source_document_id' => $this->sourceDocumentId,
            'expected_version' => $this->expectedVersion,
            'data' => $this->attributes,
        ];
    }
}
