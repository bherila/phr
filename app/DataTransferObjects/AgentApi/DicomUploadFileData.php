<?php

namespace App\DataTransferObjects\AgentApi;

use Bherila\McpLaravelBridge\Http\AgentApiFile;
use Bherila\McpLaravelBridge\Http\AgentApiMultipart;
use InvalidArgumentException;

/** Bounded base64 bridge for a single DICOM object in an MCP request. */
final readonly class DicomUploadFileData
{
    public const int MCP_MAX_BASE64_CHARACTERS = 180_000;

    private function __construct(
        public string $filename,
        public string $contents,
        public ?string $relativePath,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromBase64(array $validated): self
    {
        $encoded = $validated['content_base64'] ?? null;
        if (! is_string($encoded) || strlen($encoded) > self::MCP_MAX_BASE64_CHARACTERS) {
            throw new InvalidArgumentException('The DICOM content is invalid or too large for MCP.');
        }
        $contents = base64_decode($encoded, true);
        if (! is_string($contents) || $contents === '') {
            throw new InvalidArgumentException('The DICOM content is not valid base64.');
        }

        $relativePath = $validated['relative_path'] ?? null;

        return new self(
            filename: (string) $validated['filename'],
            contents: $contents,
            relativePath: is_string($relativePath) && $relativePath !== '' ? $relativePath : null,
        );
    }

    public function toMultipart(): AgentApiMultipart
    {
        return new AgentApiMultipart(
            fields: ['relative_path' => $this->relativePath],
            files: ['file' => new AgentApiFile($this->filename, $this->contents)],
        );
    }
}
