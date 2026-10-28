<?php

namespace App\Services\PHR\Import;

use Illuminate\Database\Eloquent\Model;

class PhrImportModelUpserter
{
    /**
     * Bookkeeping every import sets afresh. A difference in these alone is not a
     * change: it neither rewrites the row nor reopens its review.
     */
    private const array VOLATILE = ['imported_at'];

    /**
     * @template TModel of Model
     *
     * An existing row (matched by import source and external id) is written only
     * when the attributes change it; `wasChanged()` on the returned model says
     * whether they did. With `$reviewOnChange`, a change also sets that review
     * status, so a person reviews what the source changed.
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function upsert(string $modelClass, array $attributes, ?string $reviewOnChange = null): Model
    {
        $queryAttributes = null;
        if (! empty($attributes['import_source']) && ! empty($attributes['external_id'])) {
            $queryAttributes = [
                'patient_id' => $attributes['patient_id'],
                'import_source' => $attributes['import_source'],
                'external_id' => $attributes['external_id'],
            ];
        }

        if ($queryAttributes === null) {
            return $modelClass::query()->create($attributes);
        }

        $existing = $modelClass::query()->where($queryAttributes)->first();
        if ($existing !== null) {
            $volatile = array_intersect_key($attributes, array_flip(self::VOLATILE));
            $existing->fill(array_diff_key($attributes, ['review_status' => true], $volatile));
            if ($existing->isDirty()) {
                $existing->fill($volatile);
                if ($reviewOnChange !== null) {
                    $existing->setAttribute('review_status', $reviewOnChange);
                }
            }
            $existing->save();
            $existing->wasRecentlyCreated = false;

            return $existing;
        }

        return $modelClass::query()->create($attributes);
    }
}
