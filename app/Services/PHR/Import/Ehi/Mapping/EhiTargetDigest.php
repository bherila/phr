<?php

namespace App\Services\PHR\Import\Ehi\Mapping;

use App\Models\PhrOfficeVisit;
use Illuminate\Database\Eloquent\Model;

/**
 * Digest of a clinical record's stored attributes, captured at preview and
 * re-checked at apply so a proposal never lands on a record that changed since
 * the reviewer saw it. Timestamps are included: any write counts as a change.
 *
 * A proposal's digest must describe the state the proposal was built from. Mappings
 * read the patient's records once and yield drafts for a long time afterwards, so each
 * draft carries the digest of what it read rather than the service reading it later.
 *
 * A visit's coded encounter columns (#410) count only once they hold a value. A
 * proposal previewed before they existed digested a row without them, and adding
 * null columns changes nothing that row says, so it must not turn every pending
 * proposal stale. Setting one is a write and does change the digest.
 */
final class EhiTargetDigest
{
    public static function of(Model $record): string
    {
        $attributes = $record->getAttributes();
        if ($record instanceof PhrOfficeVisit) {
            foreach (PhrOfficeVisit::ENCOUNTER_CORE as $column) {
                if (array_key_exists($column, $attributes) && $attributes[$column] === null) {
                    unset($attributes[$column]);
                }
            }
        }
        ksort($attributes, SORT_STRING);
        $normalized = array_map(
            static fn (mixed $value): ?string => $value === null ? null : (string) $value,
            $attributes,
        );

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
