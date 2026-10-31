<?php

namespace App\Services\PHR\NativeBackup;

use App\Support\PHR\PhrDocumentSource;

/**
 * Keeps archives that hold a legacy document source restorable.
 *
 * `phr_documents.source` once held free-text values (`cli_pdf`, `fhir`,
 * labels carried over from migrated data) that are outside
 * PhrDocument::SOURCES. A data migration maps the stored rows; an archive
 * downloaded before it still holds the old value. Restore writes rows with raw
 * inserts, so without this the old value would come straight back, and an
 * archived record would no longer hash like the row the migration produced
 * from it, reading as a conflict against the very row it came from.
 *
 * Like PhrNativeLifecycleCompatibility, the value is normalized *after* the
 * reader has verified the stored content hash against what was written, and
 * the hash is re-derived so the planner compares like with like.
 */
final class PhrNativeDocumentSourceCompatibility
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
            || ! is_array($record['relationships'] ?? null)
            || ! array_key_exists('source', $attributes)
            || PhrDocumentSource::isValid($attributes['source'])) {
            return $record;
        }

        $attributes['source'] = PhrDocumentSource::normalize($attributes['source']);
        $normalized = (new PhrNativeRecordCodec)->record(
            (string) $record['nativeId'],
            $attributes,
            $record['relationships'],
        );

        return [...$record, 'attributes' => $normalized['attributes'], 'contentHash' => $normalized['contentHash']];
    }
}
