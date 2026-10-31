<?php

namespace App\Services\PHR\Import;

use App\Models\PhrEhiUpload;

/** What imports.create did with a document PHR's own parsers read. */
final readonly class PhrStructuredDocumentImport
{
    /** Imported directly; the records wait for review on their pages. */
    public const string IMPORTED = 'imported';

    /** A ZIP staged as an upload and queued for intake. */
    public const string QUEUED = 'queued';

    /** A ZIP already staged by an earlier call; its upload as it stands. */
    public const string UNCHANGED = 'unchanged';

    private function __construct(
        public string $outcome,
        /** ccda, fhir, or zip (intake decides between EHI Tables and MyChart). */
        public string $format,
        public ?PhrImportResult $result,
        public ?PhrEhiUpload $upload,
    ) {}

    public static function imported(string $format, PhrImportResult $result): self
    {
        return new self(self::IMPORTED, $format, $result, null);
    }

    public static function staged(PhrEhiUpload $upload, bool $queued): self
    {
        return new self($queued ? self::QUEUED : self::UNCHANGED, 'zip', null, $upload);
    }
}
