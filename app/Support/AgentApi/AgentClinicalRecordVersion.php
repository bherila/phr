<?php

namespace App\Support\AgentApi;

use App\Models\PhrDocument;
use App\Models\PhrEob;
use App\Models\PhrHealthLog;
use App\Models\PhrMedicationFill;
use App\Services\PHR\Documents\PhrDocumentRelations;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AgentClinicalRecordVersion
{
    public function for(Model $record): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('APP_KEY is required to version clinical records.');
        }

        $attributes = $record->getAttributes();
        if ($record instanceof PhrEob) {
            // Moving legacy snapshots to bounded history rows is representation
            // only. Retain a monotonic revision, including before backfill, so
            // A -> B -> A cannot make a stale EOB version current again.
            $parsed = isset($attributes['parsed_data']) ? json_decode($attributes['parsed_data'], true, flags: JSON_THROW_ON_ERROR) : null;
            $legacy = is_array($parsed) && is_array($parsed['corrections'] ?? null) ? count($parsed['corrections']) : 0;
            $attributes['correction_revision'] = max((int) ($attributes['correction_revision'] ?? 0), $legacy);
            if (is_array($parsed)) {
                unset($parsed['corrections']);
                $attributes['parsed_data'] = json_encode($parsed, JSON_THROW_ON_ERROR);
            }
        }
        // A fill's updated_at also moves when its claim links change, so sync clients see those
        // changes. Links aren't the fill's own columns, so they must not refuse a client's write.
        if ($record instanceof PhrMedicationFill) {
            unset($attributes['updated_at']);
        }
        // A log's version is what the log itself says. A read loads it with its entry count
        // and latest entry time, which are its entries'. Its updated_at also moves whenever an
        // entry is added, edited or deleted (the entry touches its log, so a client syncing on
        // updated_at sees the log change), but that is not the log's own change and must not
        // refuse a client's health_logs.update (#446); an edit of the log's own fields still
        // changes the hash. name_reserved only mirrors deleted_at for the unique index (#444).
        if ($record instanceof PhrHealthLog) {
            unset($attributes['entries_count'], $attributes['latest_entry_at'], $attributes['updated_at'], $attributes['name_reserved']);
        }
        // A document's addendum or amendment link is a row of its own (phr#409) but part of
        // what the document says: a link-only change must move the version, even within
        // the second updated_at resolves (phr#430). A document with no link hashes its
        // columns alone, so its version is what it was before links counted.
        if ($record instanceof PhrDocument) {
            $relation = PhrDocumentRelations::versionState($record);
            if ($relation !== null) {
                $attributes['relates_to'] = $relation;
            }
        }
        ksort($attributes);

        return hash_hmac(
            'sha256',
            json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $key,
        );
    }
}
