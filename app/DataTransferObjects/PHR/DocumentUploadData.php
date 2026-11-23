<?php

namespace App\DataTransferObjects\PHR;

use App\Support\PHR\PhrDocumentTags;
use App\Support\PHR\PhrReviewStatus;
use Illuminate\Http\UploadedFile;

final readonly class DocumentUploadData
{
    /** @param list<string> $tags */
    private function __construct(
        public UploadedFile $file,
        public ?string $title,
        public string $documentType,
        public ?string $observedAt,
        public ?string $summary,
        public array $tags,
        public string $source,
        public ?string $importSource,
        public ?string $externalId,
        public string $reviewStatus,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(
        UploadedFile $file,
        array $validated,
        string $source = 'manual_upload',
        ?string $importSource = null,
        ?string $externalId = null,
    ): self {
        return new self(
            file: $file,
            title: self::nullableString($validated['title'] ?? null),
            documentType: (string) $validated['document_type'],
            observedAt: self::nullableString($validated['observed_at'] ?? null),
            summary: self::nullableString($validated['summary'] ?? null),
            tags: PhrDocumentTags::normalize($validated['tags'] ?? []),
            source: $source,
            importSource: $importSource,
            externalId: $externalId,
            reviewStatus: self::reviewStatusFor($source),
        );
    }

    /**
     * A person's own upload (the browser, or a console command run by one) is
     * confirmed, as a record they enter by hand is. Any other upload, an agent's
     * among them, waits for that person's review: an agent never asserts that
     * what it wrote is correct.
     */
    private static function reviewStatusFor(string $source): string
    {
        return $source === 'manual_upload' ? PhrReviewStatus::CONFIRMED : PhrReviewStatus::PENDING;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
