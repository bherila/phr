<?php

namespace App\Services\PHR\NativeBackup;

use App\Support\PHR\PhrDocumentSource;
use App\Support\PHR\PhrDocumentType;

/**
 * Keeps archives that hold a legacy document source or type restorable.
 *
 * `phr_documents.source` once held free-text values (`cli_pdf`, `fhir`,
 * labels carried over from migrated data) that are outside
 * PhrDocument::SOURCES, and `document_type` could hold a value outside
 * PhrDocument::DOCUMENT_TYPES (the column's original `general` default). A
 * data migration maps the stored sources; an archive downloaded before it still
 * holds the old value. Restore writes rows with raw inserts, so without this
 * the old value would come straight back, and an archived record would no
 * longer hash like the row the migration produced from it, reading as a
 * conflict against the very row it came from.
 *
 * Like PhrNativeLifecycleCompatibility, the values are normalized *after* the
 * reader has verified the stored content hash against what was written, and
 * the hash is re-derived so the planner compares like with like. The planner
 * maps the current row's projection the same way, so a row that still holds a
 * legacy value matches its own unchanged backup.
 */
final class PhrNativeDocumentCompatibility
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function normalize(string $table, array $record): array
    {
        $attributes = $record['attributes'] ?? null;
        if ($table !== 'phr_documents'
            || ! is_array($attributes)
            || ! is_array($record['relationships'] ?? null)) {
            return $record;
        }

        $changed = false;
        if (array_key_exists('source', $attributes) && ! PhrDocumentSource::isValid($attributes['source'])) {
            $attributes['source'] = PhrDocumentSource::normalize($attributes['source']);
            $changed = true;
        }
        if (array_key_exists('document_type', $attributes) && ! PhrDocumentType::isValid($attributes['document_type'])) {
            $attributes['document_type'] = PhrDocumentType::normalize($attributes['document_type']);
            $changed = true;
        }
        if (! $changed) {
            return $record;
        }

        $normalized = (new PhrNativeRecordCodec)->record(
            (string) $record['nativeId'],
            $attributes,
            $record['relationships'],
        );

        return [...$record, 'attributes' => $normalized['attributes'], 'contentHash' => $normalized['contentHash']];
    }
}
