<?php

namespace App\Support\PHR;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The three states a mirrorable record can be in, derived rather than stored.
 *
 * `deleted_at` and `retracted_at` are the facts; this is the vocabulary built
 * from them, so the name a client sees can never disagree with the timestamps
 * that produced it. They are genuinely different events -- a person removing a
 * record, and the integration that wrote it withdrawing the claim -- and
 * neither is `review_status = rejected`, which records a human refusing the
 * content rather than either party removing it.
 */
final class PhrRecordLifecycle
{
    public const string ACTIVE = 'active';

    public const string RETRACTED = 'retracted';

    public const string DELETED = 'deleted';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ACTIVE, self::RETRACTED, self::DELETED];
    }

    /**
     * Restrict a query to records that still stand.
     *
     * Deletion is already handled by the soft-delete global scope, so only
     * retraction needs stating. Keeping it in one place is what stops a new
     * read path from quietly serving withdrawn records.
     *
     * A model opts in by casting `retracted_at`, which is the same declaration
     * that says it has the column. The shared read paths serve resources beyond
     * the ones this lifecycle covers -- health logs among them -- so a model
     * that has not opted in is left alone rather than queried for a column it
     * does not have.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function scopeLive(Builder $query): void
    {
        $model = $query->getModel();
        if (! $model->hasCast('retracted_at')) {
            return;
        }

        $query->whereNull($model->qualifyColumn('retracted_at'));
    }

    /** Deletion wins over retraction: it is the later and more final of the two. */
    public static function of(Model $record): string
    {
        if ($record->getAttribute('deleted_at') !== null) {
            return self::DELETED;
        }

        return $record->getAttribute('retracted_at') !== null ? self::RETRACTED : self::ACTIVE;
    }
}
