<?php

namespace App\DataTransferObjects\AgentApi;

use App\Support\AgentApi\AgentClinicalResourceCatalog;
use InvalidArgumentException;

/** Validated partial update for a patient-scoped existing clinical record. */
final readonly class ClinicalRecordUpdateData
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function __construct(
        public string $resource,
        public string $expectedVersion,
        public bool $sourceDocumentSpecified,
        public ?int $sourceDocumentId,
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
            expectedVersion: (string) $validated['expected_version'],
            sourceDocumentSpecified: array_key_exists('source_document_id', $validated),
            sourceDocumentId: array_key_exists('source_document_id', $validated) && $validated['source_document_id'] !== null
                ? (int) $validated['source_document_id']
                : null,
            attributes: (array) ($validated['data'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toRequestPayload(): array
    {
        $sourceDocumentSpecified = $this->sourceDocumentSpecified;

        return array_filter([
            'expected_version' => $this->expectedVersion,
            'source_document_id' => $this->sourceDocumentSpecified ? $this->sourceDocumentId : null,
            'data' => $this->attributes === [] ? null : $this->attributes,
        ], static fn (mixed $value, string $key): bool => match ($key) {
            'source_document_id' => $sourceDocumentSpecified,
            default => $value !== null,
        }, ARRAY_FILTER_USE_BOTH);
    }
}
