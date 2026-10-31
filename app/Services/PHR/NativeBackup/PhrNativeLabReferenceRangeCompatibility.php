<?php

namespace App\Services\PHR\NativeBackup;

use App\Support\PHR\PhrLabReferenceRangeText;

/**
 * Keeps archives that hold a doubled lab reference range from bringing it back.
 *
 * A one-off load stored some `phr_lab_results.reference_range_text` values
 * twice ("NEGNEG"), and a data migration stored them once. An archive taken
 * before that migration still holds the doubled value. Restore writes rows with
 * raw inserts, so without this the doubled text would come straight back, and
 * the archived record would no longer hash like the row the migration produced
 * from it, reading as a conflict against the very row it came from.
 *
 * Like PhrNativeDocumentSourceCompatibility, the value is repaired *after* the
 * reader has verified the stored content hash against what was written, and
 * the hash is re-derived so the planner compares like with like.
 */
final class PhrNativeLabReferenceRangeCompatibility
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function normalize(string $table, array $record): array
    {
        $attributes = $record['attributes'] ?? null;
        if ($table !== 'phr_lab_results'
            || ! is_array($attributes)
            || ! is_array($record['relationships'] ?? null)
            || ! array_key_exists('reference_range_text', $attributes)) {
            return $record;
        }

        $single = PhrLabReferenceRangeText::undoubled($attributes['reference_range_text']);
        if ($single === null) {
            return $record;
        }

        $attributes['reference_range_text'] = $single;
        $normalized = (new PhrNativeRecordCodec)->record(
            (string) $record['nativeId'],
            $attributes,
            $record['relationships'],
        );

        return [...$record, 'attributes' => $normalized['attributes'], 'contentHash' => $normalized['contentHash']];
    }
}
