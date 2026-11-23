<?php

namespace App\DataTransferObjects\PHR;

use App\Support\PHR\PhrClinicalNote;
use App\Support\PHR\PhrDocumentTags;
use Illuminate\Support\Str;

/**
 * documents.create_text (phr#409): a markdown note an agent writes, stored as a
 * `.md` object exactly as an uploaded file is. Its identity is the agent's
 * external_id in its OAuth client's namespace; it always lands pending_review.
 */
final readonly class DocumentTextData
{
    /**
     * The largest body, in bytes. It and the rest of the request fit the MCP
     * request cap (agent_api.mcp_max_body_bytes, 256 KiB) with room to spare
     * for JSON escaping.
     */
    public const int MAX_BODY_BYTES = 180_000;

    /**
     * @param  list<string>  $tags
     * @param  array<string, string|null>  $note  the PhrClinicalNote::COLUMNS given
     * @param  array{document_id: int, relation: string}|null  $relatesTo
     */
    private function __construct(
        public string $body,
        public string $filename,
        public ?string $title,
        public string $documentType,
        public ?string $observedAt,
        public ?string $summary,
        public array $tags,
        public string $importSource,
        public string $externalId,
        public array $note,
        public ?array $relatesTo,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated, string $importSource): self
    {
        $title = self::nullableString($validated['title'] ?? null);
        $note = [];
        foreach (PhrClinicalNote::COLUMNS as $column) {
            if (array_key_exists($column, $validated)) {
                $note[$column] = in_array($column, PhrClinicalNote::MOMENTS, true)
                    ? PhrClinicalNote::moment($validated[$column])
                    : self::nullableString($validated[$column]);
            }
        }
        $relatesTo = is_array($validated['relates_to'] ?? null)
            ? ['document_id' => (int) $validated['relates_to']['document_id'], 'relation' => (string) $validated['relates_to']['relation']]
            : null;

        return new self(
            body: (string) $validated['body'],
            filename: self::filename($title, (string) $validated['external_id']),
            title: $title,
            documentType: (string) $validated['document_type'],
            observedAt: self::nullableString($validated['observed_at'] ?? null),
            summary: self::nullableString($validated['summary'] ?? null),
            tags: PhrDocumentTags::normalize($validated['tags'] ?? []),
            importSource: $importSource,
            externalId: (string) $validated['external_id'],
            note: $note,
            relatesTo: $relatesTo,
        );
    }

    /** A readable `.md` file name from the title, else the external id. */
    private static function filename(?string $title, string $externalId): string
    {
        $base = Str::slug(Str::limit($title ?? $externalId, 120, ''));

        return ($base === '' ? 'note' : $base).'.md';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
