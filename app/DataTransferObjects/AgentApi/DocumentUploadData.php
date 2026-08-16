<?php

namespace App\DataTransferObjects\AgentApi;

use App\Services\AgentApi\Client\AgentApiFile;
use App\Services\AgentApi\Client\AgentApiMultipart;
use InvalidArgumentException;

final readonly class DocumentUploadData
{
    public const int MCP_MAX_BASE64_CHARACTERS = 180_000;

    /** @param list<string> $tags */
    private function __construct(
        public string $externalId,
        public string $filename,
        public string $contents,
        public ?string $title,
        public string $documentType,
        public ?string $observedAt,
        public ?string $summary,
        public array $tags,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromBase64(array $validated): self
    {
        $encoded = $validated['content_base64'] ?? null;
        if (! is_string($encoded) || strlen($encoded) > self::MCP_MAX_BASE64_CHARACTERS) {
            throw new InvalidArgumentException('The document content is invalid or too large for MCP.');
        }
        $contents = base64_decode($encoded, true);
        if (! is_string($contents) || $contents === '') {
            throw new InvalidArgumentException('The document content is not valid base64.');
        }

        $tags = $validated['tags'] ?? [];

        return new self(
            externalId: (string) $validated['external_id'],
            filename: (string) $validated['filename'],
            contents: $contents,
            title: self::nullableString($validated['title'] ?? null),
            documentType: (string) $validated['document_type'],
            observedAt: self::nullableString($validated['observed_at'] ?? null),
            summary: self::nullableString($validated['summary'] ?? null),
            tags: is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [],
        );
    }

    public function toMultipart(): AgentApiMultipart
    {
        return new AgentApiMultipart(
            fields: [
                'external_id' => $this->externalId,
                'title' => $this->title,
                'document_type' => $this->documentType,
                'observed_at' => $this->observedAt,
                'summary' => $this->summary,
                'tags' => $this->tags,
            ],
            files: ['file' => new AgentApiFile($this->filename, $this->contents)],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
