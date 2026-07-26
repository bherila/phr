<?php

namespace App\Services\PHR\Import;

use Illuminate\Database\Eloquent\Model;

class PhrImportModelUpserter
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function upsert(string $modelClass, array $attributes): Model
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
            $existing->update($attributes);
            $existing->wasRecentlyCreated = false;

            return $existing;
        }

        return $modelClass::query()->create($attributes);
    }
}
